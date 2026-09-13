{{--
    A PDF of one or more report cards. Rendered by ReportCardRenderer through
    dompdf with remote resources, PHP and JavaScript all disabled.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>{!! $css !!}</style>
</head>
<body>
@foreach ($cards as $card)
    @include('reports.card', ['card' => $card, 'isLast' => $loop->last, 'logo' => $logo])
@endforeach
</body>
</html>
