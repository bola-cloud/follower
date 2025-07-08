@extends('layouts.app')

@section('content')
<div class="container">
    <h1>WebSocket Active Users</h1>

    <div class="card mt-4">
        <div class="card-body">
            <h4>Currently connected users:
                <span class="badge bg-primary">{{ $activeConnections }}</span>
            </h4>
        </div>
    </div>
</div>
@endsection
