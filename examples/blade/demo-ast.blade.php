@exec($__ONE_COMPONENT_REGISTRY__ = ['tasks' => $__template__.'sessions.tasks', 'projects' => $__template__.'sessions.projects', 'counter' => 'sessions.tasks.count', 'demo' => $__template__.'demo.fetch', 'baseLayout' => $__layout__.'base', 'alert' => $__blade_custom_path__]) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@let($name = 'John Doe')
@let($posts = [
    ['title' => 'Post 1', 'content' => 'Content of post 1'],
    ['title' => 'Post 2', 'content' => 'Content of post 2'],
    ['title' => 'Post 3', 'content' => 'Content of post 3'],
])
@wrapper
@exec($a = 10, $b = 20, $c = $a + $b)
    <div @hydrate('div-1')>
        <p @hydrate('div-1-p-1')>Value of a: {{ $a }}</p>
        <p @hydrate('div-1-p-2')>Value of b: {{ $b }}</p>
        <p @hydrate('div-1-p-3')>Value of c (a + b): {{ $c }}</p>
    </div>
    <div @hydrate('div-2')>
        @exec($email = 'Jane Smith', $age = 30)
        <p @hydrate('div-2-p-1')>Name: {{ $name }}</p>
        <p @hydrate('div-2-p-2')>Email: {{ $email }}</p>
        <p @hydrate('div-2-p-3')>Age: {{ $age }}</p>
    </div>
    @exec($__env->startSection($__ONE_COMPONENT_REGISTRY__['tasks'].'_0'))
@startMarker('component', 'component-1')
@include($__template__.'demo.fetch', ['users' => $users])
@endMarker('component', 'component-1')
@exec($__env->stopSection())
@exec($__tasks__0_content = $__env->yieldContent($__ONE_COMPONENT_REGISTRY__['tasks'].'_0'))
@startMarker('component', 'component-2')
@include($__template__.'sessions.tasks', ['__ONE_CHILDREN_CONTENT__' => $__tasks__0_content])
@endMarker('component', 'component-2')
    @startMarker('component', 'component-3')
    @include($__template__.'sessions.tasks', ['title' => 'Custom Task List'])
    @endMarker('component', 'component-3')
    @exec($__env->startSection($__ONE_COMPONENT_REGISTRY__['projects'].'_2'))
<div @hydrate('div-3') @class(['header'])>
            <h2 @hydrate('div-3-h2-1')>My Projects @if(!($t = count($projects))) Rỗng @else ({{ $t }}) @endif</h2>
        </div>
        <div @hydrate('div-4') @class(['footer'])>
            <p @hydrate('div-4-p-1')>Total Projects: {{ count($projects) }}</p>
        </div>
        @exec($__env->startSection($__ONE_COMPONENT_REGISTRY__['tasks'].'_1'))
<div @hydrate('div-5') @class(['header'])>
                <h3 @hydrate('div-5-h3-1')>Task Owners</h3>
                <p @hydrate('div-5-p-2')>{{$content}}</p>
                @exec($test = 'Hello World')
                @foreach($posts as $post)
                    <p @hydrate("div-5-foreach-1-{$loop->index}-p-1")>{{$content}}</p>
                    @exec($content = 'This is a test')
                    <p @hydrate("div-5-foreach-1-{$loop->index}-p-2")>{{ $post['title'] }}: {{ $post['content'] }} {{ $content }}</p>
                @endforeach
            </div>
            <p @hydrate('p-6')>{{$content}}</p>
            @startMarker('component', 'component-4')
            @include($__template__.'demo.fetch', ['users' => $users])
            @endMarker('component', 'component-4')
            @startMarker('reactive', 'rc-if-1', ['stateKey' => [], 'type' => 'if'])
            @if(!($person = getPerson()))
                <p @hydrate('rc-if-1-case_1-p-1')>No person found.</p>
            @else
                <p @hydrate('rc-if-1-case_2-p-1')>Person found: {{ $person->name }}</p>
            @endif
            @endMarker('reactive', 'rc-if-1')
@exec($__env->stopSection())
@exec($__tasks__1_content = $__env->yieldContent($__ONE_COMPONENT_REGISTRY__['tasks'].'_1'))
@startMarker('component', 'component-5')
@include($__template__.'sessions.tasks', ['owners' => ['Alice', 'Bob'], '__ONE_CHILDREN_CONTENT__' => $__tasks__1_content])
@endMarker('component', 'component-5')
@exec($__env->stopSection())
@exec($__projects__2_content = $__env->yieldContent($__ONE_COMPONENT_REGISTRY__['projects'].'_2'))
@startMarker('component', 'component-6')
@include($__template__.'sessions.projects', ['projects' => $projects, '__ONE_CHILDREN_CONTENT__' => $__projects__2_content])
@endMarker('component', 'component-6')
    @startMarker('component', 'component-7')
    @include('sessions.tasks.count')
    @endMarker('component', 'component-7')
    @startMarker('component', 'component-8')
    @include($__template__.'demo.fetch')
    @endMarker('component', 'component-8')
    @startMarker('component', 'component-9')
    @include($__blade_custom_path__, ['type' => "success", 'message' => "This is a custom alert component!"])
    @endMarker('component', 'component-9')
@endWrapper
