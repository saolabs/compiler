@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@useState($active, true)
@useState($width, 120)
@useState($label, 'nút')
@wrapper
<div @class([$__VIEW_ID__ . '-e1', 'box', 'box-active'=> $active]) @attr(['title' => $label, 'data-label'=> $label]) @style([ 'width'=> $width ])>Hộp</div>
    <input @class([$__VIEW_ID__ . '-e2']) @attr(['type' => 'text', 'v-model' => 'label']) @bind($label)>

    {{-- :attr đứng MỘT MÌNH trên element thì thành attr="{{ expr }}".
         Lưu ý: nếu trước nó có directive chứa ':' trong ngoặc nhọn
         (@style, @attr, hay @class có điều kiện) thì nó KHÔNG được đổi —
         xem docs/05-roadmap.md §8. Cả bản Python lẫn PHP đều vậy. --}}
    <span @class([$__VIEW_ID__ . '-e3']) @attr(['title' => $label])>rút gọn hoạt động ở đây</span>
@endWrapper
