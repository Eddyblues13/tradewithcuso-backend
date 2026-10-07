<x-mail::message>
@if ($kind === 'paid')
# Payment received
{{ $subscription->user->name }} paid for **{{ $subscription->plan_name }}** and the plan is now active.
@else
# Bank transfer to confirm
{{ $subscription->user->name }} says they have transferred the money for **{{ $subscription->plan_name }}**. Check the account, then activate the plan from the dashboard.
@endif

<x-mail::table>
| | |
| :-- | --: |
| Reference | {{ $subscription->reference }} |
| Amount | ₦{{ number_format($subscription->amount) }} |
| Trader | {{ $subscription->user->email }} |
| Phone | {{ $subscription->user->phone ?: '—' }} |
</x-mail::table>

<x-mail::button :url="$adminUrl">
Open in the dashboard
</x-mail::button>
</x-mail::message>
