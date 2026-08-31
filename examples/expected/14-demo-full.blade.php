@exec($__ONE_COMPONENT_REGISTRY__ = ['UserItem' => $__template__ . 'users.item', 'UserList' => $__template__ . 'users.list', 'user-form' => $__template__ . 'forms.user-form', 'UserGroup' => $__template__ . 'users.group']) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

<?php if(!isset($name) || (!$name && $name !== false)) $name = 'Hello'; if(!isset($age) || (!$age && $age !== false)) $age = 18; if(!isset($items) || (!$items && $items !== false)) $items = []; ?>
@vars($users = [['id'=> 1, 'name'=> 'Lâm', 'email'=> 'lam#domain.com'], ['id'=> 2,'name'=> 'Hồng', 'email'=> "hong#domain.com"]], $title = "Hồ sơ người dùng")
@let($status = 1,
    $article = [
        'title'=> "Bài viết đầu tiên",
        'content'=> "Nội dung bài viết",
        'author'=> "Lâm",
        'createdAt'=> "2022-01-01",
        'updatedAt'=> "2022-01-01"
    ])
@useState($formData, [
        'name'=> $name,
        'age'=> $age
    ])
@useState($count, 0)
@const($MAX_FOR_LOOP_COUNT = 100)
@const([$editingMode, $setEditingMode] = useState(false))
@wrapper
<div @class([$__VIEW_ID__ . '-e1', 'click-section'])>
        <button @class([$__VIEW_ID__ . '-e11']) @attr(['type' => 'button'])>
            Click me (@startMarker('output', 'e11o1'){{ $count }}@endMarker('output', 'e11o1'))
        </button>
    </div>
    <div @class([$__VIEW_ID__ . '-e2'])>
        @startMarker('reactive', 'e2r1', ['stateKey' => ['editingMode'], 'type' => 'if'])
        @if($editingMode)
            <div @class([$__VIEW_ID__ . '-e2r1k11', 'editor-section'])>
                <h1 @class([$__VIEW_ID__ . '-e2r1k111'])>@startMarker('output', 'e2r1k111o1'){{ $name }}@endMarker('output', 'e2r1k111o1') - @startMarker('output', 'e2r1k111o2'){{ $age }}@endMarker('output', 'e2r1k111o2')</h1>
                @startMarker('component', 'e2r1k11c1')
                @include($__template__ . 'forms.user-form', ['user' => ['name'=>$name,'age'=>$age]])
                @endMarker('component', 'e2r1k11c1')
            </div>
        @else
            <div @class([$__VIEW_ID__ . '-e2r1k21', 'viewer-section'])>
                <h1 @class([$__VIEW_ID__ . '-e2r1k211'])>@startMarker('output', 'e2r1k211o1'){{ $name }}@endMarker('output', 'e2r1k211o1') - @startMarker('output', 'e2r1k211o2'){{ $age }}@endMarker('output', 'e2r1k211o2')</h1>
                <ul @class([$__VIEW_ID__ . '-e2r1k212'])>
                    @startMarker('reactive', 'e2r1k212l1', ['stateKey' => ['items'], 'type' => 'foreach'])
                    @foreach($items as $item)
                        <li @class([$__VIEW_ID__ . "-e2r1k212l11-{$loop->index}"])>@startMarker('output', "e2r1k212l11o1-{$loop->index}"){{ $item }}@endMarker('output', "e2r1k212l11o1-{$loop->index}")</li>
                    @endforeach
                    @endMarker('reactive', 'e2r1k212l1')
                </ul>
            </div>
        @endif
        @endMarker('reactive', 'e2r1')

    </div>
    <div @class([$__VIEW_ID__ . '-e3', 'users'])>
        @startMarker('reactive', 'e3l1', ['stateKey' => ['users'], 'type' => 'foreach'])
        @foreach($users as $user)
            @startMarker('component', "e3l1c1-{$user->id}")
            @include($__template__ . 'users.item', ['user' => $user, 'config' => [$editingMode]])
            @endMarker('component', "e3l1c1-{$user->id}")
        @endforeach
        @endMarker('reactive', 'e3l1')
    </div>
    @startMarker('component', 'c1')
    @include($__template__ . 'users.list', ['users' => $users])
    @endMarker('component', 'c1')
    @startMarker('component', 'c2')
    @exec($__env->startSection($__ONE_COMPONENT_REGISTRY__['UserGroup'].'_0'))
@startMarker('reactive', 'c2l1', ['stateKey' => ['users'], 'type' => 'foreach'])
@foreach($users as $user)
            @startMarker('component', "c2l1c1-{$user->id}")
            @include($__template__ . 'users.item', ['user' => $user])
            @endMarker('component', "c2l1c1-{$user->id}")
        @endforeach
        @endMarker('reactive', 'c2l1')
@exec($__env->stopSection())
@exec($__UserGroup__0_content = $__env->yieldContent($__ONE_COMPONENT_REGISTRY__['UserGroup'].'_0'))
@include($__template__ . 'users.group', ['users' => $users, 'title' => "Nhóm người dùng", '__ONE_CHILDREN_CONTENT__' => $__UserGroup__0_content])
@endMarker('component', 'c2')

    {{-- @switch/@case + @computed: status dùng để rẽ nhánh HIỂN THỊ, statusLabel
         là state dẫn xuất (memo hoá) từ CHÍNH biến status đó — hai cách khác
         nhau để phản ứng với cùng một giá trị. --}}
    <div @class([$__VIEW_ID__ . '-e4', 'status-section'])>
        @startMarker('reactive', 'e4r1', ['stateKey' => [], 'type' => 'switch'])
        @switch($status)
            @case(1)
                <span @class([$__VIEW_ID__ . '-e4r1k11', 'status-badge', 'status-draft'])>Bản nháp</span>
                @break
            @case(2)
                <span @class([$__VIEW_ID__ . '-e4r1k21', 'status-badge', 'status-published'])>Đã xuất bản</span>
                @break
            @default
                <span @class([$__VIEW_ID__ . '-e4r1k31', 'status-badge'])>Không rõ trạng thái</span>
        @endswitch
        @endMarker('reactive', 'e4r1')
        <small @class([$__VIEW_ID__ . '-e41'])>({{ $statusLabel }})</small>
    </div>

    {{-- Tổ hợp @class + @style + @attr + :attr rút gọn CÙNG một element —
         cả bốn nguồn phải gom vào đúng MỘT @class/@attr/@style, không phải
         bốn directive rời rạc (xem docs/05-roadmap.md §8③). So sánh `>` bên
         trong @class không được cắt nhầm thẻ (§8②). --}}
    <article @class([$__VIEW_ID__ . '-e5', 'article-card', 'article-card--long'=> strlen($article->content) > 10]) @attr(['title' => $article->title, 'data-status'=> $status]) @style([ 'border-color'=> $status === 1 ? 'orange' : 'green' ])>
        <h2 @class([$__VIEW_ID__ . '-e51'])>@startMarker('output', 'e51o1'){{ $article->title }}@endMarker('output', 'e51o1')</h2>
        <p @class([$__VIEW_ID__ . '-e52'])>{{ $article->content }}</p>
        <small @class([$__VIEW_ID__ . '-e53'])>{{ $article->author }} — {{ $article->createdAt }}</small>
    </article>

    {{-- Form: sự kiện client-side không có "modifier" rút gọn kiểu .prevent —
         gọi thẳng event.preventDefault() bên trong handler khai ở
         <script setup>. Trộn method của view (setStatus, handleFormSubmit)
         với helper toàn cục (String) trong CÙNG một biểu thức. --}}
    <form @class([$__VIEW_ID__ . '-e6'])>
        <input @class([$__VIEW_ID__ . '-e61']) @attr(['type' => 'text', 'value' => $statusLabel])>
        <button @class([$__VIEW_ID__ . '-e62']) @attr(['type' => 'submit', 'disabled' => $MAX_FOR_LOOP_COUNT < 1])>
            Lưu ({{ $status }})
        </button>
    </form>
@endWrapper
