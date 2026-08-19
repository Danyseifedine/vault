<x-mail::message>
# You've been invited

{{ $inviterName }} has invited you to **{{ $organizationName }}** on The Vault - the place this team keeps its environment variables.

<x-mail::button :url="$acceptUrl">
Accept the invitation
</x-mail::button>

You'll set a password and turn on two-factor authentication before you get in. Both are required.

This link expires {{ $expiresAt->diffForHumans() }}. If you weren't expecting it, you can ignore this email - nothing happens until you open the link.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
