{{--
    Layout for people-facing email (App\Notifications\EducaMail). Laravel's
    notification template, with the school's name in the header and footer.
    No remote images: many mail clients block them, and school logos are served
    behind authentication.
--}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('educa.apps.portal_url')">
{{ $schoolName ?? config('app.name') }}
</x-mail::header>
</x-slot:header>

@if (! empty($greeting))
# {{ $greeting }}
@else
# @lang('Hello!')
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" :color="$level === 'error' ? 'error' : 'primary'">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@endif

@isset($actionText)
<x-slot:subcopy>
<x-mail::subcopy>
If the “{{ $actionText }}” button does not work, copy this link into your browser: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
@if ($schoolName)
Sent by Educa on behalf of {{ $schoolName }}.
@else
Sent by Educa.
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
