@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@vars($user = null, $counter = 0)
@await
@extends('layout')
@block('content')
    <div @hydrate('block-content-div-1')>
        @startMarker('reactive', 'block-content-div-1-rc-if-1', ['stateKey' => [], 'type' => 'if'])
        @if($user)
            <h1 @hydrate('block-content-div-1-rc-if-1-case_1-h1-1')>Welcome ${$user.name}</h1>
        @else
            <p @hydrate('block-content-div-1-rc-if-1-case_2-p-1')>Please login first</p>
        @endif
        @endMarker('reactive', 'block-content-div-1-rc-if-1')
    </div>
    <div @hydrate('block-content-div-2')>
        <button @hydrate('block-content-div-2-button-1') @click(doAction("login"))>Login</button>
        <button @hydrate('block-content-div-2-button-2') @click(doAction("incrementCounter"))>Increment Counter</button>
        <p @hydrate('block-content-div-2-p-3')>Counter: ${$counter}</p>
    </div>
@endblock
@block('footer')
    <footer @hydrate('block-footer-footer-1')>Copyright 2026</footer>
@endblock
@section('sidebar', 'posts')
