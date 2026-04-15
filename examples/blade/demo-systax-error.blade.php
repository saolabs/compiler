@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@wrapper
<h1 @hydrate('h1-1')>{{ $title }}</h1>
    <p @hydrate('p-2')>{{ $content }}</p>
@endWrapper
