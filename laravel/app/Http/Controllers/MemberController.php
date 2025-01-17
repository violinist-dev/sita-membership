<?php

namespace App\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Models\MailingList;
use App\Models\Member;
use App\Models\MemberMailingPreference;
use App\Models\MemberRejectionStatus;
use App\Models\MembershipType;
use App\Models\Team;
use App\Notifications\AcceptanceNotification;
use App\Notifications\EndorsementNotification;
use App\Notifications\PastDueSubReminder;
use App\Notifications\RejectionNotification;
use App\Notifications\SubReminder;
use App\Repositories\MemberMembershipStatusRepository;
use App\Repositories\MemberRepository;
use App\Repositories\MembershipStatusesRepository;
use App\Repositories\MembershipTypeRepository;
use App\Services\SitaOnlineService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class MemberController extends Controller
{
    protected $sitaOnlineService;
    public MemberRepository $rep;

    public function __construct(SitaOnlineService $sitaOnlineService)
    {
        $this->sitaOnlineService = $sitaOnlineService;
        $this->rep = new MemberRepository();
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Member::class);

        $validatedData = $request->validate([
            'search' => 'nullable|string|max:255',
            'membership_status_id' => 'nullable|integer|exists:membership_statuses,id',
        ]);

        $membership_status_ids = [];

        $search = $validatedData['search'] ?? '';
        $membership_status_id = $validatedData['membership_status_id'] ?? '';

        if ($membership_status_id) {
            $membership_status_ids = [$membership_status_id];
        }

        $rep = new MemberRepository();
        $members = $rep->filterMembers($membership_status_ids, $search);

        $pagedMembers = $members
            ->with('membershipType', 'title', 'membershipStatus')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Members/Index', [
            'filters' => $request->only('membership_status_id', 'search'),
            'members' => $pagedMembers,
        ]);
    }

    /**
     * Show the form for sign up of new member.
     */
    public function signup(Request $request)
    {
        // If already had a member, redirect - unless they have a permission.
        $user = $request->user();
        $team = Team::first();

        if (! $user->hasTeamPermission($team, 'member:create_many')) {
            $member = Member::where('user_id', $user->id)->first();

            if ($member) {
                return redirect()->route('members.signup.index', $member->id);
            }
        }

        return Inertia::render('Members/Signup', [
            'options' => [
                'membership_type_options' => MembershipType::all(['id', 'code', 'title']),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function storeSignup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'membership_type_id' => 'required|int|min:1',
        ]);

        $member = new Member();
        $member->fill($validated);
        // set non fillable fields
        $member->membership_status_id = MembershipStatus::DRAFT->value;
        $member->user_id = $request->user()->id;
        $member->save();

        return redirect()->route('members.signup.index', $member->id)
            ->with('data', [
                'id' => $member->id,
            ])
            ->with('success', 'Member Added');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // unused
    }

    /**
     * Submit member application.
     */
    public function submit(Member $member, Request $request): RedirectResponse
    {
        $this->authorize('submit', $member);

        $this->rep->updateMembershipStatus($member, MembershipStatus::SUBMITTED);

        // find any old rejection reasons and mark inactive
        $mrs = MemberRejectionStatus::where(['member_id' => $member->id, 'status' => 1])->first();
        if ($mrs != null) {
            $mrs->status = false;
            $mrs->save();
        }
        // add record to member membership status
        $this->rep->recordAction($member, $request->user());
        // Send endorsement notifications.
        $team = Team::first();
        $users = $team->allUsers();
        foreach ($users as $user) {
            if ($team->userHasPermission($user, 'member:endorse')) {
                $user->notify(new EndorsementNotification($member));
            }
        }

        return redirect()->back()->with('success', 'Application Submitted');
    }

    /**
     * Mark Viewed Other Memberships or Mailing List as true.
     */
    public function markOptionalFlagAsViewed(Member $member, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'flag_name' => 'required|string',
        ]);

        $flag_name = $validated['flag_name'];
        $this->rep->markOptionalFlagAsViewed($member, $flag_name);

        return redirect()->back();
    }

    /**
     * Endorse member application.
     */
    public function endorse(Member $member, Request $request): RedirectResponse
    {
        $this->authorize('endorse', $member);

        $this->rep->updateMembershipStatus($member, MembershipStatus::ENDORSED);

        // add record to member membership status
        $this->rep->recordAction($member, $request->user());
        // Send acceptance notifications.
        $team = Team::first();
        $users = $team->allUsers();
        foreach ($users as $user) {
            if ($team->userHasPermission($user, 'member:accept')) {
                $user->notify(new AcceptanceNotification($member));
            }
        }

        return redirect()->back()->with('success', 'Application Endorsed');
    }

    /**
     * Accept member application.
     */
    public function accept(Member $member, Request $request): RedirectResponse
    {
        $this->authorize('accept', $member);

        $rep = new MembershipTypeRepository();
        $isFreeMembership = $this->sitaOnlineService->isMemberHasFreeMembership($member);

        if ($isFreeMembership) {
            $validated = $request->validate([
                'financial_year' => 'required|int|min:2000',
                'receipt_number' => 'nullable|string',
            ]);

            $this->rep->accept(
                $member,
                $request->user(),
                $validated['financial_year'],
                $validated['receipt_number'] ?? ''
            );
        } else {
            $validated = $request->validate([
                'financial_year' => 'required|int|min:2000',
                'receipt_number' => 'required|string',
            ]);

            $this->rep->accept(
                $member,
                $request->user(),
                $validated['financial_year'],
                $validated['receipt_number']
            );
        }

        return redirect()->back()->with('success', 'Application Accepted');
    }

    /**
     * Reject member application.
     */
    public function reject(Member $member, Request $request): RedirectResponse
    {
        $this->authorize('reject', $member);

        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $this->rep->reject(
            $member,
            $validated['reason']
        );

        $this->rep->recordAction($member, $request->user());

        // Send rejection notification.
        $member->user->notify(new RejectionNotification($member, $validated['reason']));

        return redirect()->back()->with('success', 'Application Rejected');
    }

    /**
     * Get Rejection Reason.
     */
    public function getRejectionReason(Member $member)
    {
        $status = MemberRejectionStatus::where(['member_id' => $member->id, 'status' => 1])->first();
        if ($status['reason'] != null) {
            return $status['reason'];
        }

        return null;
    }

    /**
     * Converts Member Status from Rejected to Draft.
     */
    public function convertRejectToDraft(Member $member)
    {
        $this->authorize('submit', $member);

        if ($member->membership_status_id == MembershipStatus::REJECTED->value) {
            $member->membership_status_id = MembershipStatus::DRAFT->value;
            $member->save();
        }

        return redirect()->back()->with('success', 'Appllication Status updated to DRAFT.');
    }

    /**
     * Send a sub reminder to member.
     */
    public function sendSubReminder(Member $member): RedirectResponse
    {
        $this->authorize('sendSubReminder', $member);

        // Email will be sent in a queue.
        $member->user->notify(new SubReminder($member));

        return redirect()->back()->with('success', 'Reminder scheduled.');
    }

    /**
     * Send a sub reminder to member.
     */
    public function sendPastDueSubReminder(Member $member): RedirectResponse
    {
        $this->authorize('sendPastDueSubReminder', $member);

        $end_grace_period = Carbon::now();
        $rep = new MemberMembershipStatusRepository();
        $statuses = $rep->getByMemberIdAndStatusId($member->id, MembershipStatus::ACCEPTED->value);
        if ($statuses->count()) {
            $end_grace_period = Carbon::createFromFormat('Y-m-d', $statuses[0]->to_date);
        }

        // Email will be sent in a queue.
        $member->user->notify(new PastDueSubReminder($member, $end_grace_period->addMonthsWithoutOverflow(6)));

        return redirect()->back()->with('success', 'Reminder scheduled.');
    }

    /**
     * Toggle Member Subscription to Mailing List.
     */
    public function toggleMailingListSubscription(Member $member, Request $request)
    {
        $this->authorize('view', $member);

        $validated = $request->validate([
            'mailing_list_id' => 'required|int|min:1',
            'subscribe' => 'required|bool',
        ]);

        $mailing_list = MailingList::find($validated['mailing_list_id']);
        $foundPref = MemberMailingPreference::where('member_id', $member->id)
            ->where('mailing_list_id', $mailing_list->id)->first();
        $subscribe = $validated['subscribe'];
        if ($foundPref) {
            $foundPref->subscribed = $subscribe;
            $foundPref->update();
        } else {
            $member_mailing_preference = new MemberMailingPreference();
            $member_mailing_preference->mailing_list_id = $mailing_list->id;
            $member_mailing_preference->member_id = $member->id;
            $member_mailing_preference->subscribed = $subscribe;
            $member_mailing_preference->save();
        }

        return redirect()->back()
            ->with('success', ($subscribe ? 'Subscribed to ' : 'Unsubscribed from ').$mailing_list->title);
    }

    /**
     * Display the specified resource.
     */
    public function show(Member $member): Response
    {
        $this->authorize('view', $member);

        $relations = [
            'membershipType',
            'membershipStatus',
        ];
        // Load title if exists.
        if ($member->title_id) {
            $relations[] = 'title';
        }

        $reason = null;
        if (MemberRejectionStatus::where(['member_id' => $member->id, 'status' => 1])->first() != null) {
            $reason = $this->getRejectionReason($member);
        }

        $rep = new MemberMembershipStatusRepository();
        $statuses = $rep->getByMemberIdAndStatusId($member->id, MembershipStatus::ACCEPTED->value);
        $rep = new MembershipStatusesRepository();
        $membershipStatuses = $rep->get();

        return Inertia::render('Members/Show', [
            'member' => $member->load($relations),
            'statuses' => $statuses,
            'options' => [
                'completion' => $member->completions,
            ],
            'data' => [
                'reason' => $reason,
            ],
            'membershipStatuses' => $membershipStatuses,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Member $member)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Member $member): RedirectResponse
    {
        $this->authorize('update', $member);

        $validated = $request->validate([
            'membership_type_id' => 'required|int|min:1',
            'first_name' => 'required_with:membership_type_id|string|max:255',
            'last_name' => 'required_with:membership_type_id|string|max:255',
            'title_id' => 'nullable|int',
            'dob' => 'nullable|date',
            'gender_id' => 'required_with:membership_type_id|int|min:1',
            'job_title' => 'required_with:membership_type_id|string|max:255',
            'current_employer' => 'required_with:membership_type_id|string|max:255',
            'home_address' => 'nullable|max:255',
            'home_phone' => 'nullable|max:255',
            'home_mobile' => 'nullable|max:255',
            'home_email' => 'nullable|email|max:255',
            'work_address' => 'nullable|max:255',
            'work_phone' => 'nullable|max:255',
            'work_mobile' => 'nullable|max:255',
            'work_email' => 'nullable|email|max:255',
            'other_membership' => 'nullable|max:500',
            // 'membership_status_id' => 'int',
            'note' => 'nullable',
            'membership_status_id' => 'nullable|int',
        ]);

        $member->update($validated);

        return redirect()->back()->with('success', 'Member Updated');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Member $member)
    {
        //
    }

    public function export(Request $request)
    {
        $this->authorize('viewAny', Member::class);

        $validated = $request->validate([
            'membership_status_id' => 'nullable|int',
            'search' => 'nullable|string|max:255',
        ]);

        $membership_status_id = $validated['membership_status_id'] ?? '';
        $search = $validated['search'] ?? '';

        $export = $this->sitaOnlineService->getMembersExport($membership_status_id, $search);

        $filename = 'members_'.date('YmdHis').'.xlsx';

        return Excel::download($export, $filename);
    }
}
