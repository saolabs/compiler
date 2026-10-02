
<?php if(!array_key_exists('initial', get_defined_vars())) $initial = 0; if(!array_key_exists('title', get_defined_vars())) $title = 'Bộ đếm'; ?>
@let($cardPath = $__base__ . 'components.card')
@useState($count, $initial)
@useState($label, '')
@const($step = 1)
@php($doubled = $count * 2)
@exec($__ONE_COMPONENT_REGISTRY__ = ['Card' => $cardPath]) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}
@wrapper
<section @class([$__VIEW_ID__ . '-e1'])>
        <h1 @class([$__VIEW_ID__ . '-e11'])>@startMarker('output', 'e11o1'){{ $title }}@endMarker('output', 'e11o1')</h1>
        <img @class([$__VIEW_ID__ . '-e12']) @attr(['src' => asset('static/examples/assets/images/logo.svg'), 'alt' => ''])><img @class([$__VIEW_ID__ . '-e13']) @attr(['src' => asset('static/examples/assets/images/icon.svg'), 'alt' => ''])>

        <button @class([$__VIEW_ID__ . '-e14'])>Tăng</button>
        <button @class([$__VIEW_ID__ . '-e15'])>Đặt lại</button>

        <p @class([$__VIEW_ID__ . '-e16'])>count = @startMarker('output', 'e16o1'){{ $count }}@endMarker('output', 'e16o1') · doubled = @startMarker('output', 'e16o2'){{ $doubled }}@endMarker('output', 'e16o2')</p>
        @startMarker('reactive', 'e1r1', ['stateKey' => ['label'], 'type' => 'if'])
        @if($label !== '')
            <p @class([$__VIEW_ID__ . '-e1r1k11'])>@startMarker('output', 'e1r1k11o1'){{ $label }}@endMarker('output', 'e1r1k11o1')</p>
        @endif
        @endMarker('reactive', 'e1r1')

        @startMarker('component', 'e1c1')
        @include($cardPath)
        @endMarker('component', 'e1c1')
    </section>
@endWrapper
