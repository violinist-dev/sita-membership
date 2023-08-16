<?php

namespace App\Repositories;

use App\Enums\MembershipStatus;
use App\Models\Member;
use App\Models\MemberMembershipStatus;
use App\Models\MemberRejectionStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use LaravelDaily\Invoices\Invoice;
use LaravelDaily\Invoices\Classes\Buyer;
use LaravelDaily\Invoices\Classes\InvoiceItem;

class MemberRepository extends Repository
{
    final public const MONTH_FOR_END_OF_FINANCIAL_YEAR = 6; // June.

    final public const DAYS_OF_MONTH_OF_FINANCIAL_YEAR = 30;

    public function getByMembershipStatusId(int $membership_status_id, int $limit = 10): Collection
    {
        return Member::where('membership_status_id', $membership_status_id)
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function generateEndDate(Carbon $current_dt = null)
    {
        if ($current_dt == null) {
            $current_dt = Carbon::now();
        }

        // Set end of financial year (June 30)
        $month = self::MONTH_FOR_END_OF_FINANCIAL_YEAR;
        $day = self::DAYS_OF_MONTH_OF_FINANCIAL_YEAR;

        if ($current_dt->month > $month) {
            $next_year = $current_dt->year + 1;

            return Carbon::create($next_year, $month, $day);
        }

        return Carbon::create($current_dt->year, $month, $day);
    }

    public function accept(Member $member, User $user, int $financial_year = 0, string $receipt_number = '')
    {
        $member->membership_status_id = MembershipStatus::ACCEPTED->value;
        $member->save();

        if ($financial_year === 0) {
            $financial_year = Carbon::now()->year;
        }

        $to_date = $this->generateEndDate(
            Carbon::createFromDate(
                $financial_year + 1,
                self::MONTH_FOR_END_OF_FINANCIAL_YEAR,
                self::DAYS_OF_MONTH_OF_FINANCIAL_YEAR
            )
        );

        $this->recordAction($member, $user, $to_date, $receipt_number);

        // generate invoice and store

        // send email notification to user with link to invoice page

        // allow pdf download
    }

    public function reject(Member $member, string $reason)
    {
        $member->membership_status_id = MembershipStatus::REJECTED->value;
        $member->save();

        $member_rejected = new MemberRejectionStatus(['member_id' => $member->id, 'reason' => $reason]);
        $member_rejected->save();
    }

    public function markOptionalFlagAsViewed(Member $member, string $flag)
    {
        if ($flag == 'viewed_other_memberships') {
            $member->viewed_other_memberships = true;
            $member->save();
        } elseif ($flag == 'viewed_mailing_list') {
            $member->viewed_mailing_list = true;
            $member->save();
        }
    }

    public function recordAction(Member $member, User $user, $to_date = null, string $receipt_number = '')
    {
        $membership_status = new MemberMembershipStatus([
            'member_id' => $member->id,
            'membership_status_id' => $member->membership_status_id,
            'user_id' => $user->id,
            'from_date' => Carbon::now(),
            'receipt_number' => $receipt_number,
        ]);
        if ($to_date) {
            $membership_status->to_date = $to_date;
        }

        return $membership_status->save();
    }

    /**
     * Filter member list.
     *
     * @param  array  $membership_status_id
     * @param  string  $search
     * @return Collection
     */
    public function filterMembers($membership_status_id, $search)
    {
        return Member::orderBy('first_name')
            ->when(
                $membership_status_id,
                fn ($query) => $query->where(
                    'membership_status_id',
                    $membership_status_id
                )
            )
            ->when(
                $search,
                fn ($query) => $query->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('job_title', 'like', '%'.$search.'%')
                    ->orWhere('current_employer', 'like', '%'.$search.'%')
            );
    }

    /**
     * Generate Invoice for membership subscription.
     *
     * @param  Member  $member
     * @return Collection
     */
    public function generateInvoice(Member $member) {
        $customer = new Buyer([
            'name'          => $member->first_name . ' ' . $member->last_name,
            'custom_fields' => [
                'email' => $member->home_email,
            ],
        ]);

        $item = (new InvoiceItem())->title('SITA Membership Subscription')
          ->pricePerUnit($member->membershipType->annual_cost);

        $invoice = Invoice::make()
            ->buyer($customer)
            ->taxRate(15)
            ->addItem($item);

        return $invoice->stream();
    }
}
