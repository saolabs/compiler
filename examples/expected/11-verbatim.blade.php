@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@useState($title, 'thật')
@wrapper
<p @class([$__VIEW_ID__ . '-e1'])>@startMarker('output', 'e1o1'){{ $title }}@endMarker('output', 'e1o1')</p>
    @verbatim
    <pre><code>{{ title }} và @children giữ nguyên</code></pre>
    @endverbatim
@endWrapper
