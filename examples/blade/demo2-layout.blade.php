@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@pageStart
@wrapper
{{-- đây là trang layout --}}

<div @class([$__VIEW_ID__ . '-d69e6b1d', 'container'])>
    @useBlock('content')
    {{-- tương đương vói dạng @blockoutlet('content') hay <block-outlet name="content"></block-outlet> --}}
</div>
@endWrapper

@pageEnd
