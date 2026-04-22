@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

<?php if(!isset($title) || (!$title && $title !== false)) $title = 'test'; if(!isset($description) || (!$description && $description !== false)) $description = 'Mô tả'; if(!isset($user) || (!$user && $user !== false)) $user = request()->user(); ?>
@let($test = 'demo')
@useState($userState, $user)
@useState($counter, 0)
@useState($posts, [
    ['title'=> 'title 1', 'description'=> 'Mô tả 1'],
    ['title'=> 'title 2', 'description'=> 'Mô tả 2'],
    ['title'=> 'title 3', 'description'=> 'Mô tả 3'],
    ])
@const([$userAvatar, $setAvatar] = useState(getUserAvatar($user)))
@extends($__template__ . 'main')
    @section('meta:type', 'article')
    @section('meta:og:image', 'https://vcc.vn/static/images/thumbnai.jpg')
    @block('footer')
        <div @hydrate('block-footer-div-1') @class(['footer-container'])>
            Footer Content
        </div>
    @endblock
    @block('content')
        <main @hydrate('block-content-main-1')>
            <header @hydrate('block-content-main-1-header-1')>
                <nav @hydrate('block-content-main-1-header-1-nav-1')>
                    <a @hydrate('block-content-main-1-header-1-nav-1-a-1') @attr(['href' => route('web.home'), 'title' => siteinfo('site_name')])>
                        <img @hydrate('block-content-main-1-header-1-nav-1-a-1-img-1') @attr(['src' => asset('static/web/images/loho.png'), 'alt' => siteinfo('site_name')]) @class(['site-logo', 'has-login'=> $userState]) />
                    </a>
                    <ul @hydrate('block-content-main-1-header-1-nav-1-ul-2') @class(['site-menu'])>
                        @startMarker('reactive', 'block-content-main-1-header-1-nav-1-ul-2-foreach-1', ['stateKey' => ['posts'], 'type' => 'foreach'])
                        @foreach($posts as $post)
                            <li @hydrate("block-content-main-1-header-1-nav-1-ul-2-foreach-1-{$loop->index}-li-1") @class(['menu-item', 'nav-item'])>
                                <a @hydrate("block-content-main-1-header-1-nav-1-ul-2-foreach-1-{$loop->index}-li-1-a-1") @class(['nav-link']) @attr(['href' => webPostUrl($post)])>
                                    {{ $post->title }}
                                </a>
                            </li>
                        @endforeach
                        @endMarker('reactive', 'block-content-main-1-header-1-nav-1-ul-2-foreach-1')
                    </ul>
                    <div @hydrate('block-content-main-1-header-1-nav-1-div-3') @class(['account'])>
                        @startMarker('reactive', 'block-content-main-1-header-1-nav-1-div-3-rc-if-1', ['stateKey' => ['userState'], 'type' => 'if'])
                        @if($userState)
                            <a @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-a-1') @class(['account-btn', 'btn-show-menu']) @attr(['href' => route('web.account'), 'data-menu-target' => 'account-menu'])>
                                <img @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-a-1-img-1') @class(['account-image', 'user-avatar']) @attr(['src' => getUserAvatar($userState), 'alt' => $userState->name]) />
                            </a>
                            <ul @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-ul-2') @class(['account-menu']) @attr(['id' => 'account-menu'])>
                                {{--  --}}
                                <li @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-ul-2-li-1')><a @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-ul-2-li-1-a-1') @attr(['href' => route('web.account')])>{{ text('web.account.dashboard') }}</a></li>
                                <li @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-ul-2-li-2')><a @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-ul-2-li-2-a-1') @attr(['href' => route('web.account.profile')])>{{ text('web.account.profile') }}</a></li>
                                <li @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-ul-2-li-3')><a @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-ul-2-li-3-a-1') @attr(['href' => route('web.account.change-avatar')]) @click(changeAvatar(event))>{{ text('web.account.change-avatar') }}</a></li>
                                <li @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-ul-2-li-4')><a @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_1-ul-2-li-4-a-1') @attr(['href' => route('web.account.signout')]) @click(signout())>{{ text('web.account.signout') }}</a></li>
                            </ul>
                        @else
                            <a @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_2-a-1') @attr(['href' => route('web.account.signin')])>{{ text('web.account.signin') }}</a>
                            <a @hydrate('block-content-main-1-header-1-nav-1-div-3-rc-if-1-case_2-a-2') @attr(['href' => route('web.account.signup')])>{{ text('web.account.signup') }}</a>
                        @endif
                        @endMarker('reactive', 'block-content-main-1-header-1-nav-1-div-3-rc-if-1')
                    </div>
                </nav>

            </header>
            <h1 @hydrate('block-content-main-1-h1-2') @class(['page-title'])>{{ $title }}</h1>
            <p @hydrate('block-content-main-1-p-3')>{{ $description }}</p>
            <div @hydrate('block-content-main-1-div-4') @class(['card'])>
                <button @hydrate('block-content-main-1-div-4-button-1') @click($setCounter($counter + 1))>@startMarker('output', 'block-content-main-1-div-4-button-1-output-1'){{ text('web.contents.clickme', ['counter'=> $counter]) }}@endMarker('output', 'block-content-main-1-div-4-button-1-output-1')</button>
            </div>

        </main>
    @endblock
