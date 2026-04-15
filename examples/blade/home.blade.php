@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@wrapper
<tasks @hydrate('tasks-1')>
        <demo @hydrate('tasks-1-demo-1') @attr([':users' => 'users']) />
    </tasks>
    <tasks @hydrate('tasks-2') @attr(['title' => ''Custom Task List'']) />
    <projects @hydrate('projects-3') @attr([':projects' => 'projects'])>
        <div @hydrate('projects-3-div-1') @class(['header'])>
            <h2 @hydrate('projects-3-div-1-h2-1')>My Projects</h2>
        </div>
        <div @hydrate('projects-3-div-2') @class(['footer'])>
            <p @hydrate('projects-3-div-2-p-1')>Total Projects: {{ count($projects) }}</p>
        </div>
        <tasks @hydrate('projects-3-tasks-3') @attr([':owners' => '['Alice', 'Bob']'])>
            <div @hydrate('projects-3-tasks-3-div-1') @class(['header'])>
                <h3 @hydrate('projects-3-tasks-3-div-1-h3-1')>Task Owners</h3>
            </div>
            <demo @hydrate('projects-3-tasks-3-demo-2') @attr([':users' => 'users']) />
        </tasks>
    </projects>
    <counter @hydrate('counter-4')></counter>
    <demo @hydrate('demo-5')></demo>
    <alert @hydrate('alert-6') @attr(['type' => 'success', 'message' => 'This is a custom alert component!'])></alert>
@endWrapper
