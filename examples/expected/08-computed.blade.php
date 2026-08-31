@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@useState($price, 100)
@useState($qty, 2)
@wrapper
<p @class([$__VIEW_ID__ . '-e1'])>Tổng: {{ $total }}</p>
@endWrapper
