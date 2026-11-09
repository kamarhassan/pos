@extends('backend.master')

@section('title', 'Add Patient')

@section('content')
<div class="card">
  <form action="{{ route('backend.admin.patients.store') }}" method="post">
    @csrf
    @include('backend.patients._form', ['patient' => null, 'submitLabel' => 'Create patient'])
  </form>
</div>
@endsection
