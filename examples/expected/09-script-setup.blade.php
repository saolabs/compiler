@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@useState($n, 0)
@wrapper
<button @class([$__VIEW_ID__ . '-e1'])>Nhân đôi: @startMarker('output', 'e1o1'){{ $n }}@endMarker('output', 'e1o1')</button>
@endWrapper
