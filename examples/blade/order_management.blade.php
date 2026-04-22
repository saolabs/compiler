@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

<?php if(!isset($pageTitle) || (!$pageTitle && $pageTitle !== false)) $pageTitle = 'Quản lý đơn hàng'; if(!isset($currentUser) || (!$currentUser && $currentUser !== false)) $currentUser = request()->user(); if(!isset($filters) || (!$filters && $filters !== false)) $filters = [
        'status'=> 'all',
        'search'=> ''
    ]; ?>
@useState($orders, [])
@useState($isLoading, true)
@useState($stats, [ 'total'=> 0, 'revenue'=> 0 ])
@useState($sidebarOpen, true)
@useState($debugMode, true)
@useState($totalPages, 10)
@useState($currentPage, 1)
@let($i = 3)
@let($i = $i - 1)
@let(i = 3)
@let(i = i - 1)
@extends('layouts.admin')

    @block('content')
        <div @hydrate('block-content-div-1') @class(['admin-wrapper']) @class(['sidebar-collapsed'=> !$sidebarOpen])>
            
            {{-- Header & Stats --}}
            <header @hydrate('block-content-div-1-header-1') @class(['flex', 'justify-between', 'items-center', 'mb-8'])>
                <h1 @hydrate('block-content-div-1-header-1-h1-1')>{{ $pageTitle }}</h1>
                <div @hydrate('block-content-div-1-header-1-div-2') @class(['user-profile', 'flex', 'items-center', 'gap-3'])>
                    @startMarker('reactive', 'block-content-div-1-header-1-div-2-rc-if-1', ['stateKey' => [], 'type' => 'if'])
                    @if($currentUser && $currentUser->is_admin) {
                        <span @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-span-1') @class(['badge', 'badge-admin'])>Admin Mode</span>
                    } @else {
                        <span @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-span-2') @class(['badge', 'badge-staff'])>Staff</span>
                    }
                    @startMarker('reactive', 'block-content-div-1-header-1-div-2-rc-if-1-case_1-rc-if-1', ['stateKey' => [], 'type' => 'if'])
                    @if($currentUser) {
                        <img @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-rc-if-1-case_1-img-1') @class(['w-10', 'h-10', 'rounded-full']) @attr(['src' => $currentUser->avatar, 'alt' => 'avatar']) />
                    }
                </div>
            </header>

            {{-- Thống kê nhanh --}}
            <div @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3') @class(['grid', 'grid-cols-4', 'gap-4', 'mb-6'])>
                @each (key, value in stats) {
                    <div @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1') @class(['stat-card', 'p-4', 'bg-white', 'shadow', 'rounded-lg'])>
                        <label @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-label-1') @class(['text-gray-500', 'uppercase', 'text-xs', 'font-bold'])>{{ $key }}</label>
                        <div @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-2') @class(['text-2xl', 'font-semibold'])>
                            @startMarker('reactive', 'block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-2-rc-switch-1', ['stateKey' => [], 'type' => 'switch'])
                            @switch($key) {
                                @case('revenue'):
                                    <span @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-2-rc-switch-1-case_1-span-1')>${{ formatMoney($value) }}</span>
                                @break
                                @default:
                                    <span @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-2-rc-switch-1-case_1-span-2')>{{ $value }}</span>
                            }
                        </div>
                    </div>
                }
            </div>

            {{-- Bộ lọc --}}
            <section @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-section-3') @class(['filters-bar', 'mb-6', 'p-4', 'bg-gray-50', 'rounded', 'flex', 'gap-4'])>
                <input @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-section-3-input-1') @attr(['type' => 'text', 'placeholder' => 'Tìm mã đơn hàng...']) @input(searchOrders(event->target->value)) />
                
                <select @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-section-3-select-2') @change(setStatusFilter(event->target->value))>
                    @each (status in ['all', 'pending', 'shipping', 'completed', 'cancelled']) {
                        <option @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-section-3-select-2-option-1') @attr(['value' => $status]) @selected($filters->status == $status)>
                            {{ text('status.' + $status) }}
                        </option>
                    }
                </select>
            </section>

            {{-- Bảng dữ liệu chính --}}
            <div @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4') @class(['table-responsive', 'bg-white', 'rounded-xl', 'shadow-sm', 'overflow-hidden'])>
                @startMarker('reactive', 'block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1', ['stateKey' => ['isLoading'], 'type' => 'if'])
                @if($isLoading) {
                    <div @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-div-1') @class(['p-20', 'text-center'])>
                        <div @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-div-1-div-1') @class(['spinner'])></div>
                        <p @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-div-1-p-2')>Đang tải dữ liệu đơn hàng...</p>
                    </div>
                } @elseif($orders && count($orders) > 0) {
                    <table @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2') @class(['w-full', 'text-left'])>
                        <thead @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-thead-1') @class(['bg-gray-100', 'border-b'])>
                            <tr @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-thead-1-tr-1')>
                                <th @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-thead-1-tr-1-th-1')>ID</th>
                                <th @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-thead-1-tr-1-th-2')>Khách hàng</th>
                                <th @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-thead-1-tr-1-th-3')>Sản phẩm</th>
                                <th @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-thead-1-tr-1-th-4')>Trạng thái</th>
                                <th @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-thead-1-tr-1-th-5')>Tổng tiền</th>
                                <th @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-thead-1-tr-1-th-6')>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2')>
                            @each (order in orders) {
                                <tr @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1') @class(['hover:bg-gray-50', 'transition-colors', 'border-b'])>
                                    <td @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-1') @class(['font-mono', 'text-blue-600'])>#{{ $order->id }}</td>
                                    <td @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-2')>
                                        <div @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-2-div-1') @class(['font-bold'])>{{ $order->customer->name }}</div>
                                        <div @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-2-div-2') @class(['text-xs', 'text-gray-400'])>{{ $order->customer->email }}</div>
                                    </td>
                                    <td @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-3')>
                                        <ul @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-3-ul-1') @class(['item-list'])>
                                            @each (item in order.items) {
                                                <li @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-3-ul-1-li-1') @class(['text-sm'])>
                                                    {{ $item->qty }}x {{ $item->name }}
                                                </li>
                                            }
                                        </ul>
                                    </td>
                                    <td @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-4')>
                                        <span @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-4-span-1') @class(['status-dot', 'status-' + $order->status])></span>
                                        {{ $order->status_label }}
                                    </td>
                                    <td @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-5') @class(['font-bold'])>${{ $order->total_amount }}</td>
                                    <td @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-6') @class(['actions'])>
                                        <button @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-6-button-1') @class(['btn-icon']) @click(viewDetail($order->id))>👁️</button>
                                        @startMarker('reactive', 'block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-6-rc-if-1', ['stateKey' => [], 'type' => 'if'])
                                        @if($order->status == 'pending' && $currentUser->can('edit_orders')) {
                                            <button @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-6-rc-if-1-case_1-button-1') @class(['btn-icon', 'text-green-500']) @click(approveOrder($order->id))>✅</button>
                                        }
                                        <button @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-tr-1-td-6-rc-if-1-case_1-button-2') @class(['btn-icon', 'text-red-500']) @click(deleteOrder($order->id))>🗑️</button>
                                    </td>
                                </tr>
                            }
                        </tbody>
                    </table>
                    
                    {{-- Phân trang --}}
                    <footer @hydrate('block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-footer-2') @class(['pagination', 'p-4', 'flex', 'justify-center', 'gap-2'])>
                        @startMarker('reactive', 'block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-footer-2-for-1', ['stateKey' => ['totalPages'], 'type' => 'for'])
                        @for($i = 1; $i <= $totalPages; $i++) {
                            <button @hydrate("block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-footer-2-for-1-{$i}-button-1") @class(['page-link', 'active'=> $i == $currentPage]) @click(goToPage($i))>
                                {{ $i }}
                            </button>
                        }
                    </footer>

                } @else {
                    <div @hydrate("block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-footer-2-div-1") @class(['empty-state', 'p-20', 'text-center'])>
                        <img @hydrate("block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-footer-2-div-1-img-1") @class(['mx-auto', 'mb-4']) @attr(['src' => '/static/empty-orders.svg']) />
                        <h3 @hydrate("block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-footer-2-div-1-h3-2")>Không tìm thấy đơn hàng nào</h3>
                        <p @hydrate("block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-footer-2-div-1-p-3")>Thử thay đổi bộ lọc hoặc từ khóa tìm kiếm của bạn.</p>
                        <button @hydrate("block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-footer-2-div-1-button-4") @class(['btn-primary', 'mt-4']) @click(resetFilters())>Xóa bộ lọc</button>
                    </div>
                }
            </div>

            {{-- Debug Panel (Ví dụ về While) --}}
            @startMarker('reactive', 'block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-rc-if-1', ['stateKey' => ['debugMode'], 'type' => 'if'])
            @if($debugMode) {
                <div @hydrate("block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-rc-if-1-case_1-div-1") @class(['debug-panel', 'fixed', 'bottom-0', 'right-0', 'p-4', 'bg-black', 'text-green-400', 'font-mono', 'text-xs', 'opacity-75'])>
                    
                    @startMarker('while', 'block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-rc-if-1-case_1-div-1-while-1', ['start' => $i, 'end' => 0])
                    @while($i > 0) {
                        <div @hydrate("block-content-div-1-header-1-div-2-rc-if-1-case_1-div-3-div-1-div-4-rc-if-1-case_1-table-2-tbody-2-rc-if-1-case_1-div-1-while-1-{$i}-div-1")>[DEBUG] System health check: OK ({{ $i }})</div>
                        
                    }
                </div>
            }

        </div>
    @endblock
