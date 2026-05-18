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
        <div @class([$__VIEW_ID__ . '-e085b222', 'admin-wrapper', 'sidebar-collapsed'=> !$sidebarOpen])>
            
            {{-- Header & Stats --}}
            <header @class([$__VIEW_ID__ . '-c417c587', 'flex', 'justify-between', 'items-center', 'mb-8'])>
                <h1 @class([$__VIEW_ID__ . '-241d3fd8'])>{{ $pageTitle }}</h1>
                <div @class([$__VIEW_ID__ . '-5cade1a4', 'user-profile', 'flex', 'items-center', 'gap-3'])>
                    @startMarker('reactive', 'b4ef86fc', ['stateKey' => [], 'type' => 'if'])
                    @if($currentUser && $currentUser->is_admin) {
                        <span @class([$__VIEW_ID__ . '-4111cf25', 'badge', 'badge-admin'])>Admin Mode</span>
                    } @else {
                        <span @class([$__VIEW_ID__ . '-c2dbe4b4', 'badge', 'badge-staff'])>Staff</span>
                    }
                    @startMarker('reactive', '6395ee19', ['stateKey' => [], 'type' => 'if'])
                    @if($currentUser) {
                        <img @class([$__VIEW_ID__ . '-acae5bea', 'w-10', 'h-10', 'rounded-full']) @attr(['src' => $currentUser->avatar, 'alt' => 'avatar']) />
                    }
                </div>
            </header>

            {{-- Thống kê nhanh --}}
            <div @class([$__VIEW_ID__ . '-b95e7269', 'grid', 'grid-cols-4', 'gap-4', 'mb-6'])>
                @each (key, value in stats) {
                    <div @class([$__VIEW_ID__ . '-11e74f1a', 'stat-card', 'p-4', 'bg-white', 'shadow', 'rounded-lg'])>
                        <label @class([$__VIEW_ID__ . '-82523166', 'text-gray-500', 'uppercase', 'text-xs', 'font-bold'])>{{ $key }}</label>
                        <div @class([$__VIEW_ID__ . '-8fa6b647', 'text-2xl', 'font-semibold'])>
                            @startMarker('reactive', 'fee65f41', ['stateKey' => [], 'type' => 'switch'])
                            @switch($key) {
                                @case('revenue'):
                                    <span @class([$__VIEW_ID__ . '-aff9f891'])>${{ formatMoney($value) }}</span>
                                @break
                                @default:
                                    <span @class([$__VIEW_ID__ . '-3ae01e33'])>{{ $value }}</span>
                            }
                        </div>
                    </div>
                }
            </div>

            {{-- Bộ lọc --}}
            <section @class([$__VIEW_ID__ . '-0243ac4c', 'filters-bar', 'mb-6', 'p-4', 'bg-gray-50', 'rounded', 'flex', 'gap-4'])>
                <input @class([$__VIEW_ID__ . '-4e15f876']) @attr(['type' => 'text', 'placeholder' => 'Tìm mã đơn hàng...']) />
                
                <select @class([$__VIEW_ID__ . '-7f0d7000'])>
                    @each (status in ['all', 'pending', 'shipping', 'completed', 'cancelled']) {
                        <option @class([$__VIEW_ID__ . '-fa12f1a2']) @attr(['value' => $status]) @selected($filters->status == $status)>
                            {{ text('status.' + $status) }}
                        </option>
                    }
                </select>
            </section>

            {{-- Bảng dữ liệu chính --}}
            <div @class([$__VIEW_ID__ . '-00008efd', 'table-responsive', 'bg-white', 'rounded-xl', 'shadow-sm', 'overflow-hidden'])>
                @startMarker('reactive', '829ba40f', ['stateKey' => ['isLoading'], 'type' => 'if'])
                @if($isLoading) {
                    <div @class([$__VIEW_ID__ . '-6a02e98c', 'p-20', 'text-center'])>
                        <div @class([$__VIEW_ID__ . '-8be1b6d9', 'spinner'])></div>
                        <p @class([$__VIEW_ID__ . '-16139053'])>Đang tải dữ liệu đơn hàng...</p>
                    </div>
                } @elseif($orders && count($orders) > 0) {
                    <table @class([$__VIEW_ID__ . '-0b9b3a72', 'w-full', 'text-left'])>
                        <thead @class([$__VIEW_ID__ . '-e4cb5bf5', 'bg-gray-100', 'border-b'])>
                            <tr @class([$__VIEW_ID__ . '-72cebe66'])>
                                <th @class([$__VIEW_ID__ . '-42fcbab8'])>ID</th>
                                <th @class([$__VIEW_ID__ . '-22a17521'])>Khách hàng</th>
                                <th @class([$__VIEW_ID__ . '-625d35df'])>Sản phẩm</th>
                                <th @class([$__VIEW_ID__ . '-1231c601'])>Trạng thái</th>
                                <th @class([$__VIEW_ID__ . '-a5f570f8'])>Tổng tiền</th>
                                <th @class([$__VIEW_ID__ . '-b2a25dac'])>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody @class([$__VIEW_ID__ . '-6cb48f02'])>
                            @each (order in orders) {
                                <tr @class([$__VIEW_ID__ . '-2516061b', 'hover:bg-gray-50', 'transition-colors', 'border-b'])>
                                    <td @class([$__VIEW_ID__ . '-e677f630', 'font-mono', 'text-blue-600'])>#{{ $order->id }}</td>
                                    <td @class([$__VIEW_ID__ . '-e5fa803d'])>
                                        <div @class([$__VIEW_ID__ . '-00259f44', 'font-bold'])>{{ $order->customer->name }}</div>
                                        <div @class([$__VIEW_ID__ . '-7914382d', 'text-xs', 'text-gray-400'])>{{ $order->customer->email }}</div>
                                    </td>
                                    <td @class([$__VIEW_ID__ . '-b80a8b57'])>
                                        <ul @class([$__VIEW_ID__ . '-e3f6783a', 'item-list'])>
                                            @each (item in order.items) {
                                                <li @class([$__VIEW_ID__ . '-a4311d20', 'text-sm'])>
                                                    {{ $item->qty }}x {{ $item->name }}
                                                </li>
                                            }
                                        </ul>
                                    </td>
                                    <td @class([$__VIEW_ID__ . '-94d56261'])>
                                        <span @class([$__VIEW_ID__ . '-2f4eed22', 'status-dot', 'status-' + $order->status])></span>
                                        {{ $order->status_label }}
                                    </td>
                                    <td @class([$__VIEW_ID__ . '-b69a0b32', 'font-bold'])>${{ $order->total_amount }}</td>
                                    <td @class([$__VIEW_ID__ . '-0da9fa8c', 'actions'])>
                                        <button @class([$__VIEW_ID__ . '-9f1d9200', 'btn-icon'])>👁️</button>
                                        @startMarker('reactive', '1a55dd3e', ['stateKey' => [], 'type' => 'if'])
                                        @if($order->status == 'pending' && $currentUser->can('edit_orders')) {
                                            <button @class([$__VIEW_ID__ . '-aee6cc73', 'btn-icon', 'text-green-500'])>✅</button>
                                        }
                                        <button @class([$__VIEW_ID__ . '-d4313026', 'btn-icon', 'text-red-500'])>🗑️</button>
                                    </td>
                                </tr>
                            }
                        </tbody>
                    </table>
                    
                    {{-- Phân trang --}}
                    <footer @class([$__VIEW_ID__ . '-a648ecda', 'pagination', 'p-4', 'flex', 'justify-center', 'gap-2'])>
                        @startMarker('reactive', '15d9696e', ['stateKey' => ['totalPages'], 'type' => 'for'])
                        @for($i = 1; $i <= $totalPages; $i++) {
                            <button @class([$__VIEW_ID__ . "-9a47a650-{$i}", 'page-link', 'active'=> $i == $currentPage])>
                                {{ $i }}
                            </button>
                        }
                    </footer>

                } @else {
                    <div @class([$__VIEW_ID__ . "-e08f9843-{$i}", 'empty-state', 'p-20', 'text-center'])>
                        <img @class([$__VIEW_ID__ . "-99482373-{$i}", 'mx-auto', 'mb-4']) @attr(['src' => '/static/empty-orders.svg']) />
                        <h3 @class([$__VIEW_ID__ . "-d09d2658-{$i}"])>Không tìm thấy đơn hàng nào</h3>
                        <p @class([$__VIEW_ID__ . "-bf5abe0a-{$i}"])>Thử thay đổi bộ lọc hoặc từ khóa tìm kiếm của bạn.</p>
                        <button @class([$__VIEW_ID__ . "-ab29e478-{$i}", 'btn-primary', 'mt-4'])>Xóa bộ lọc</button>
                    </div>
                }
            </div>

            {{-- Debug Panel (Ví dụ về While) --}}
            @startMarker('reactive', "7a0338e4-{$i}", ['stateKey' => ['debugMode'], 'type' => 'if'])
            @if($debugMode) {
                <div @class([$__VIEW_ID__ . "-4fea43cb-{$i}", 'debug-panel', 'fixed', 'bottom-0', 'right-0', 'p-4', 'bg-black', 'text-green-400', 'font-mono', 'text-xs', 'opacity-75'])>
                    
                    @startMarker('while', "8459570d-{$i}", ['start' => $i, 'end' => 0])
                    @while($i > 0) {
                        <div @class([$__VIEW_ID__ . "-cca5d5d8-{$i}-{$i}"])>[DEBUG] System health check: OK ({{ $i }})</div>
                        
                    }
                </div>
            }

        </div>
    @endblock
