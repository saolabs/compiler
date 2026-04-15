@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@vars($users = null, $posts = [])
@wrapper
@await
<div @hydrate('div-1')>
    @startMarker('reactive', 'div-1-rc-if-1', ['stateKey' => [], 'type' => 'if'])
    @if($user)
        <h1 @hydrate('div-1-rc-if-1-case_1-h1-1')>Welcome {{ $user->name }}</h1>
        @foreach($postList as $post)
            <div @hydrate("div-1-rc-if-1-case_1-foreach-1-{$loop->index}-div-1")>{{ $post->title }}</div>
        @endforeach
    @else
        <p @hydrate('div-1-rc-if-1-case_2-p-1')>Please login first</p>
    @endif
    @endMarker('reactive', 'div-1-rc-if-1')
</div>
<div @hydrate('div-2')>
    <button @hydrate('div-2-button-1') @click(doAction("login"))>Login</button>
    <button @hydrate('div-2-button-2') @click(doAction("fetchPosts"))>Fetch Posts</button>
</div>
@endWrapper
