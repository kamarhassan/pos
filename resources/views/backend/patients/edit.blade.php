@extends('backend.master')

@section('title', 'Edit Patient')

@section('content')
<div class="card">
  <form action="{{ route('backend.admin.patients.update', $patient->id) }}" method="post">
    @csrf
    @method('PUT')
    @include('backend.patients._form', ['submitLabel' => 'Update patient'])
  </form>
</div>
@endsection
