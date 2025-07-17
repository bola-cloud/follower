@extends('layouts.admin')

@section('content')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
  .flatpickr-calendar {
    z-index: 10000;
    width: auto;
    max-width: 300px;
  }

  .flatpickr-calendar .flatpickr-month {
    background-color: #fff;
    border-radius: 5px;
  }

  .flatpickr-calendar.open {
    visibility: visible;
    opacity: 1;
  }
</style>

<div class="row">
    <div class="col-xl-3 col-lg-6 col-12">
        <div class="card pull-up">
            <div class="card-content">
                <div class="card-body">
                    <div class="media d-flex">
                        <div class="media-body text-left">
                            <h3 class="info">{{ $usersCount }}</h3>
                            <h6>عدد المستخدمين</h6>
                        </div>
                        <div>
                            <i class="icon-user-follow info font-large-2 float-left"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-lg-6 col-12">
        <div class="card pull-up">
            <div class="card-content">
                <div class="card-body">
                    <div class="media d-flex">
                        <div class="media-body text-left">
                            <h3 class="primary">{{ $ordersTotal }}</h3>
                            <h6>عدد الطلبات</h6>
                        </div>
                        <div>
                            <i class="icon-basket primary font-large-2 float-left"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-lg-6 col-12">
        <div class="card pull-up">
            <div class="card-content">
                <div class="card-body">
                    <div class="media d-flex">
                        <div class="media-body text-left">
                            <h3 class="success">{{ $ordersCompleted }}</h3>
                            <h6>طلبات مكتملة</h6>
                        </div>
                        <div>
                            <i class="icon-check success font-large-2 float-left"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-lg-6 col-12">
        <div class="card pull-up">
            <div class="card-content">
                <div class="card-body">
                    <div class="media d-flex">
                        <div class="media-body text-left">
                            <h3 class="warning">{{ $ordersPending }}</h3>
                            <h6>طلبات قيد التنفيذ</h6>
                        </div>
                        <div>
                            <i class="icon-clock warning font-large-2 float-left"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activation Count -->
    <div class="col-xl-3 col-lg-6 col-12">
        <div class="card pull-up">
            <div class="card-content">
                <div class="card-body">
                    <div class="media d-flex">
                        <div class="media-body text-left">
                            <h3 class="danger" id="activation-count">0</h3>
                            <h6>الأجهزة المفعلة</h6>
                        </div>
                        <div>
                            <i class="icon-screen-tablet danger font-large-2 float-left"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Date Range Picker -->
{{-- <div class="row">
    <div class="col-md-6">
        <label for="start_date">Start Date</label>
        <input type="text" id="start_date" class="form-control datepicker">
    </div>
    <div class="col-md-6">
        <label for="end_date">End Date</label>
        <input type="text" id="end_date" class="form-control datepicker">
    </div>
</div> --}}

<!-- Chart Section -->
<div class="row mt-4 align-items-stretch">
    <div class="col-md-4 d-flex flex-column justify-content-center">
        <h5 class="text-center">نسبة الطلبات المكتملة</h5>
        <div style="height: 300px;">
            <canvas id="completionChart" style="height: 100% !important; width: 100%;"></canvas>
        </div>
    </div>
    <div class="col-md-8 d-flex">
        <div class="w-100" style="height: 300px;">
            <canvas id="ordersChart" style="height: 100% !important; width: 100%;"></canvas>
        </div>
    </div>

</div>

<div class="row">
    <div class="col-md-6 mt-4">
        <div style="height: 300px;">
            <canvas id="actionsChart" height="100"></canvas>
        </div>
    </div>
    <div class="col-md-6 mt-4">
        <div style="height: 300px;">
            <canvas id="usersChart" height="100"></canvas>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('assets/js/flatpickr.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    function fetchActivationCount() {
        $.get('/api/device-activation-count', function(data) {
            $('#activation-count').text(data.count);
        });
    }
    fetchActivationCount();
    setInterval(fetchActivationCount, 5000);

    // Orders Chart
    const ordersCtx = document.getElementById('ordersChart').getContext('2d');
    new Chart(ordersCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode(array_keys($orders)) !!},
            datasets: [{
                label: 'عدد الطلبات لكل شهر',
                data: {!! json_encode(array_values($orders)) !!},
            }]
        }
    });

    // Users Chart
    $.get('/api/chart/users', function(res) {
        const usersCtx = document.getElementById('usersChart').getContext('2d');
        new Chart(usersCtx, {
            type: 'line',
            data: {
                labels: res.labels,
                datasets: [{
                    label: 'عدد المستخدمين',
                    data: res.data,
                    borderWidth: 2
                }]
            }
        });
    });

    // Actions Chart
    $.get('/api/chart/actions', function(res) {
        const actionsCtx = document.getElementById('actionsChart').getContext('2d');
        new Chart(actionsCtx, {
            type: 'doughnut',
            data: {
                labels: res.labels,
                datasets: [{
                    label: 'الإجراءات',
                    data: res.data,
                }]
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelector('.card')?.classList.remove('card');
    });

        // Order Completion Circle Chart
    const completionCtx = document.getElementById('completionChart').getContext('2d');
    new Chart(completionCtx, {
        type: 'doughnut',
        data: {
            labels: ['مكتمل', 'غير مكتمل'],
            datasets: [{
                data: [{{ $ordersCompleted }}, {{ $ordersTotal - $ordersCompleted }}],
                backgroundColor: ['#28a745', '#dc3545']
            }]
        },
        options: {
            cutout: '70%',
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom'
                }
            }
        }
    });


</script>

@endpush
