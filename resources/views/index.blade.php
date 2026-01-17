@extends('backup-ui::layout')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">
                <i class="fas fa-database me-2 text-primary"></i>{{ $pageTitle }}
            </h1>
            <div class="btn-group">
                <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-plus me-1"></i>Create Backup
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" onclick="createBackup()">
                        <i class="fas fa-archive me-2"></i>Full Backup
                    </a></li>
                    <li><a class="dropdown-item" href="#" onclick="createBackup('only-db')">
                        <i class="fas fa-database me-2"></i>Database Only
                    </a></li>
                    <li><a class="dropdown-item" href="#" onclick="createBackup('only-files')">
                        <i class="fas fa-folder me-2"></i>Files Only
                    </a></li>
                </ul>
                <form method="POST" action="{{ route('backup-ui.clean') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Clean old backups according to retention policy?')">
                        <i class="fas fa-broom me-1"></i>Clean Old
                    </button>
                </form>
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>{{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if($backupDestinations->isEmpty())
            <div class="alert alert-warning" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>No backup destinations configured!</strong>
                Please configure backup destinations in your <code>config/backup.php</code> file.
            </div>
        @endif

        @foreach($backupDestinations as $destination)
            <div class="card mb-4">
                <div class="card-header bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-hdd me-2"></i>{{ ucfirst($destination['name']) }}
                            @if(isset($destination['driver']) && $destination['driver'] !== 'local')
                                <small class="text-muted">({{ strtoupper($destination['driver']) }})</small>
                            @else
                                Disk
                            @endif
                        </h5>
                        <div>
                            @if(isset($destination['reachable']) && $destination['reachable'])
                                <span class="badge bg-success">
                                    <i class="fas fa-check me-1"></i>Reachable
                                </span>
                            @else
                                <span class="badge bg-danger">
                                    <i class="fas fa-times me-1"></i>Unreachable
                                </span>
                            @endif
                            @if(isset($destination['healthy']) && $destination['healthy'])
                                <span class="badge bg-success ms-1">
                                    <i class="fas fa-heart me-1"></i>Healthy
                                </span>
                            @elseif(isset($destination['healthy']))
                                <span class="badge bg-warning ms-1">
                                    <i class="fas fa-exclamation me-1"></i>Unhealthy
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if(isset($destination['error']))
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Error:</strong> {{ $destination['error'] }}
                        </div>
                    @else
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-copy text-muted me-2"></i>
                                    <div>
                                        <small class="text-muted">Total Backups</small>
                                        <div class="fw-bold">{{ $destination['amount'] ?? 0 }}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-chart-pie text-muted me-2"></i>
                                    <div>
                                        <small class="text-muted">Used Storage</small>
                                        <div class="fw-bold">{{ $destination['usedStorage'] ?? 'Unknown' }}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-clock text-muted me-2"></i>
                                    <div>
                                        <small class="text-muted">Latest Backup</small>
                                        <div class="fw-bold">
                                            {{ $destination['newest'] ? $destination['newest']->date()->format('Y-m-d H:i:s') : 'None' }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if(isset($destination['backups']) && count($destination['backups']) > 0)
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th><i class="fas fa-calendar me-1"></i>Date Created</th>
                                            <th><i class="fas fa-weight-hanging me-1"></i>Size</th>
                                            <th><i class="fas fa-file me-1"></i>Filename</th>
                                            <th width="120"><i class="fas fa-cogs me-1"></i>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($destination['backups'] as $backup)
                                            <tr>
                                                <td>
                                                    <span class="text-muted">
                                                        {{ \Carbon\Carbon::createFromTimestamp($backup['last_modified'])->format('Y-m-d') }}
                                                    </span>
                                                    <br>
                                                    <small class="text-muted">
                                                        {{ \Carbon\Carbon::createFromTimestamp($backup['last_modified'])->format('H:i:s') }}
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark">{{ $backup['human_readable_size'] }}</span>
                                                </td>
                                                <td>
                                                    <code class="small">{{ basename($backup['path']) }}</code>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <a href="{{ route('backup-ui.download', [$destination['name'], urlencode(basename($backup['path']))]) }}"
                                                           class="btn btn-outline-primary"
                                                           title="Download backup">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                        <form method="POST"
                                                              action="{{ route('backup-ui.delete', [$destination['name'], urlencode(basename($backup['path']))]) }}"
                                                              class="d-inline">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit"
                                                                    class="btn btn-outline-danger delete-backup"
                                                                    title="Delete backup">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No backups found on this disk.</p>
                                <small class="text-muted">Create your first backup using the button above.</small>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>

<!-- Hidden form for creating backups -->
<form id="backup-form" method="POST" action="{{ route('backup-ui.create') }}" style="display: none;">
    @csrf
    <input type="hidden" id="backup-option" name="option" value="">
</form>

<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mb-0">Creating backup...</p>
                <small class="text-muted">This may take several minutes.</small>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function createBackup(option = '') {
    const messages = {
        '': 'Create a full backup (database + files)?',
        'only-db': 'Create a database-only backup?',
        'only-files': 'Create a files-only backup?'
    };

    if (confirm(messages[option] + ' This may take some time.')) {
        // Show loading modal
        const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));
        loadingModal.show();

        // Submit form
        document.getElementById('backup-option').value = option;
        document.getElementById('backup-form').submit();
    }
}

// Hide loading modal on page load (in case of redirect back)
document.addEventListener('DOMContentLoaded', function() {
    const loadingModal = bootstrap.Modal.getInstance(document.getElementById('loadingModal'));
    if (loadingModal) {
        loadingModal.hide();
    }
});
</script>
@endsection
