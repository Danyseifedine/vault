<x-mail::message>
# New sign-in

Hi {{ $name }}, your account just signed in to **{{ config('app.name') }}**.

<x-mail::table>
| | |
|:--- |:--- |
| **When** | {{ $at->format('l, j M Y \a\t H:i:s T') }} |
| **IP address** | {{ $ip }} |
| **Device** | {{ $device }} |
| **Browser** | {{ $userAgent }} |
</x-mail::table>

If this was you, there is nothing to do.

**If it was not you,** someone has your password and your second factor. Change your password now and review your account security.

<x-mail::button :url="$securityUrl">
Review account security
</x-mail::button>

Every sign-in is also recorded in the audit log.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
