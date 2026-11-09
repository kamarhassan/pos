@extends('backend.master')

@section('title', 'Patients')

@section('content')
<div class="card">
  @can('customer_create')
  <div class="mt-n5 mb-3 d-flex justify-content-end">
    <a href="{{ route('backend.admin.patients.create') }}" class="btn bg-gradient-primary">
      <i class="fas fa-plus-circle"></i>
      Add Patient
    </a>
  </div>
  @endcan
  <div class="card-body p-2 p-md-4 pt-0">
    <div class="card-body table-responsive p-0" id="table_data">
      <table id="datatables" class="table table-hover">
        <thead>
          <tr>
            <th data-orderable="false">#</th>
            <th>Full name</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Created</th>
            <th data-orderable="false">Action</th>
          </tr>
        </thead>
      </table>
    </div>
  </div>
</div>
@endsection

@push('script')
<script>
  $(function() {
    $('#datatables').DataTable({
      processing: true,
      serverSide: true,
      ordering: true,
      order: [[1, 'asc']],
      ajax: { url: "{{ route('backend.admin.patients.index') }}" },
      columns: [
        { data: 'DT_RowIndex', name: 'DT_RowIndex' },
        { data: 'full_name', name: 'full_name' },
        { data: 'phone', name: 'phone' },
        { data: 'email', name: 'email' },
        { data: 'created_at', name: 'created_at' },
        { data: 'action', name: 'action' }
      ]
    });
  });
</script>
@endpush
