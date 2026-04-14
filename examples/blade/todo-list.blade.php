@vars($users = [])
@const([$todos, $setTodos] = useState([]))
@const([$newTodo, $setNewTodo] = useState(''))
@const([$todoIndex, $setTodoIndex] = useState(0))

@wrapper
@await
<div @hydrate('div-1') @class(['col-lg-6', 'mb-4']) @attr(['data-category' => 'basic forms'])>
        <div @hydrate('div-1-div-1') @class(['example-card'])>
            <div @hydrate('div-1-div-1-div-1') @class(['example-header'])>
                <h3 @hydrate('div-1-div-1-div-1-h3-1')>Todo List</h3>
                <div @hydrate('div-1-div-1-div-1-div-2') @class(['example-tags'])>
                    <span @hydrate('div-1-div-1-div-1-div-2-span-1') @class(['tag', 'tag-basic'])>Basic</span>
                    <span @hydrate('div-1-div-1-div-1-div-2-span-2') @class(['tag', 'tag-forms'])>Forms</span>
                    <span @hydrate('div-1-div-1-div-1-div-2-span-3') @class(['tag', 'tag-list'])>Lists</span>
                </div>
            </div>

            <div @hydrate('div-1-div-1-div-2') @class(['example-description'])>
                <p @hydrate('div-1-div-1-div-2-p-1')>Interactive todo list with add, toggle, and delete functionality.</p>
            </div>

            <div @hydrate('div-1-div-1-div-3') @class(['example-demo'])>
                <div @hydrate('div-1-div-1-div-3-div-1') @class(['demo-container']) @attr(['id' => 'todo-demo'])>
                    <div @hydrate('div-1-div-1-div-3-div-1-div-1') @class(['todo-app'])>
                        <div @hydrate('div-1-div-1-div-3-div-1-div-1-div-1') @class(['input-group', 'mb-3'])>
                            <input @hydrate('div-1-div-1-div-3-div-1-div-1-div-1-input-1') @class(['form-control']) @attr(['type' => 'text', 'id' => 'todo-input', 'placeholder' => 'Add new todo...']) @bind($newTodo) @keydown(addTodoByEnter(event))>
                            <button @hydrate('div-1-div-1-div-3-div-1-div-1-div-1-button-2') @class(['btn', 'btn-primary']) @click(addTodo())>Add</button>
                        </div>
                        <ul @hydrate('div-1-div-1-div-3-div-1-div-1-ul-2') @class(['list-unstyled']) @attr(['id' => 'todo-list'])>
                            @startMarker('reactive', 'div-1-div-1-div-3-div-1-div-1-ul-2-foreach-1', ['stateKey' => ['todos'], 'type' => 'foreach'])
                            @foreach($todos as $todo)
                                <li @hydrate("div-1-div-1-div-3-div-1-div-1-ul-2-foreach-1-{$loop->index}-li-1") @class(['todo-item', '{{', '$todo->completed', '?', ''completed'', ':', '''', '}}'])>
                                    <input @hydrate("div-1-div-1-div-3-div-1-div-1-ul-2-foreach-1-{$loop->index}-li-1-input-1") @attr(['type' => 'checkbox', 'toggleTodo' => true, 'todo-' => true])>id)) @checked($todo->completed)>
                                    {{ $todo->text }}
                                    <button @hydrate("div-1-div-1-div-3-div-1-div-1-ul-2-foreach-1-{$loop->index}-li-1-button-2") @class(['btn', 'btn-sm', 'btn-outline-danger']) @attr(['deleteTodo' => true, 'todo-' => true])>id))>×</button>
                                </li>
                            @endforeach
                            @endMarker('reactive', 'div-1-div-1-div-3-div-1-div-1-ul-2-foreach-1')

                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endWrapper