@extends('backend.master')

@section('title', 'Patient Sales')

@section('content')
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Sales for {{ $patient->full_name }}</h3>
  </div>
  <div class="card-body table-responsive">
    <table class="table table-hover">
      <thead>
        <tr>
          <th>Sale</th>
          <th>Date</th>
          <th>Items</th>
          <th>Total</th>
          <th>Paid</th>
          <th>Due</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($orders as $order)
        <tr>
          <td>#{{ $order->id }}</td>
          <td>{{ $order->created_at?->format('d M, Y') }}</td>
          <td>
            @foreach ($order->products as $item)
              {{ $item->product->name ?? 'Product unavailable' }} × {{ $item->quantity }}@if (!$loop->last), @endif
            @endforeach
          </td>
          <td>{{ number_format($order->total, 2) }}</td>
          <td>{{ number_format($order->paid, 2) }}</td>
          <td>{{ number_format($order->due, 2) }}</td>
          <td>{{ $order->status ? 'Paid' : 'Due' }}</td>
          <td class="text-nowrap">
            <a class="btn btn-success btn-sm" href="{{ route('backend.admin.orders.invoice', $order->id) }}">Invoice</a>
            @if (!$order->status)
              <a class="btn btn-warning btn-sm" href="{{ route('backend.admin.due.collection', $order->id) }}">Collect payment</a>
            @endif
            <a class="btn btn-primary btn-sm" href="{{ route('backend.admin.orders.transactions', $order->id) }}">Payments</a>
          </td>
        </tr>
        @empty
        <tr>
          <td colspan="8" class="text-center">No sales found for this patient.</td>
        </tr>
        @endforelse
      </tbody>
    </table>
    {{ $orders->links() }}
  </div>
</div>
@endsection
