{{--
    A PDF of one or more report cards. Rendered by ReportCardRenderer through
    dompdf with remote resources, PHP and JavaScript all disabled.

    Each card is printed in its own template (ReportTemplate): a document can
    mix them, and $css carries the stylesheet of every template present.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    {{-- Zeroed here, not by a template: only Classic's stylesheet styles body,
         and it is absent from a document with no Classic card in it. --}}
    <style>body { margin: 0; padding: 0; }</style>
    <style>{!! $css !!}</style>
</head>
<body>
@foreach ($cards as $card)
    @include(\App\Domain\Reporting\Enums\ReportTemplate::fromCard($card)->view(), ['card' => $card, 'isLast' => $loop->last, 'logo' => $logo])
@endforeach
</body>
</html>
