@pageStart
@wrapper
{{-- đây là trang layout --}}

<div @hydrate('div-1') @class(['container'])>
    @useBlock('content')
    {{-- tương đương vói dạng @blockoutlet('content') hay <block-outlet name="content"></block-outlet> --}}
</div>
@endWrapper

@pageEnd