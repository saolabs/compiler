@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@useState($count, 0)
@useState($name, 'Sao')
@wrapper
<div @class([$__VIEW_ID__ . '-e1', 'counter'])>
        <span @class([$__VIEW_ID__ . '-e11'])>@startMarker('output', 'e11o1'){{ $name }}@endMarker('output', 'e11o1') đã bấm @startMarker('output', 'e11o2'){{ $count }}@endMarker('output', 'e11o2') lần</span>
        <button @class([$__VIEW_ID__ . '-e12'])>Tăng</button>
    </div>
@endWrapper
