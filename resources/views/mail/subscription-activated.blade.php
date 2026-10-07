<x-mail::message>
# You're in, {{ $subscription->user->name }}

Your **{{ $subscription->plan_name }}** plan is now active.

<x-mail::table>
| | |
| :-- | --: |
| Reference | {{ $subscription->reference }} |
| Amount | ₦{{ number_format($subscription->amount) }} |
| Starts | {{ $subscription->starts_at->format('j M Y') }} |
| Ends | {{ $subscription->ends_at->format('j M Y') }} |
</x-mail::table>

<x-mail::button :url="$dashboardUrl">
Go to your dashboard
</x-mail::button>

Trading carries risk. Only trade with money you can afford to lose.

{{ config('app.name') }}
</x-mail::message>
