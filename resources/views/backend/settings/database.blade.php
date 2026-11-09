@extends('backend.master')

@section('title', 'Database Backup')

@section('content')
<div class="card">
  <div class="card-body">
    <h4>Database backup and restore</h4>
    <p>Create a downloadable copy of the current SQLite database or restore a previously saved QPOS SQLite backup. Restoring replaces the current database; a safety backup is created automatically first.</p>

    <form method="post" action="{{ route('backend.admin.settings.database.backup') }}" class="mb-4">
      @csrf
      <button type="submit" class="btn bg-gradient-primary">
        <i class="fas fa-download"></i> Create backup
      </button>
    </form>

    <form method="post" action="{{ route('backend.admin.settings.database.restore') }}" enctype="multipart/form-data" onsubmit="return confirm('This replaces the current database. Continue?')">
      @csrf
      <div class="form-group">
        <label for="backup">Restore from SQLite backup</label>
        <input type="file" id="backup" name="backup" class="form-control-file" accept=".sqlite,.sqlite3,.db" required>
        <small class="form-text text-muted">The file must fit the server's upload limit. Only valid QPOS SQLite database files can be restored.</small>
        @error('backup')
        <span class="text-danger">{{ $message }}</span>
        @enderror
      </div>
      <button type="submit" class="btn btn-warning">
        <i class="fas fa-upload"></i> Restore database
      </button>
    </form>

    <hr>
    <h5>Available backups</h5>
    @if ($backups->isEmpty())
      <p class="text-muted">No backups have been created yet.</p>
    @else
      <ul class="list-group">
        @foreach ($backups as $backup)
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <strong>{{ $backup['created_at']->format('d/m/Y H:i:s') }}</strong>
              <div class="text-muted small">{{ $backup['filename'] }}</div>
            </div>
            <div class="d-flex">
              <a class="btn btn-sm btn-outline-primary mr-2" href="{{ route('backend.admin.settings.database.download', $backup['filename']) }}">
                <i class="fas fa-download"></i> Download
              </a>
              <form method="post" action="{{ route('backend.admin.settings.database.destroy', $backup['filename']) }}" onsubmit="return confirm('Delete this backup permanently?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger">
                  <i class="fas fa-trash"></i> Delete
                </button>
              </form>
            </div>
          </li>
        @endforeach
      </ul>
    @endif
  </div>
</div>
@endsection
