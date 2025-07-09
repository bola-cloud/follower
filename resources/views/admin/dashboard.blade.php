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
                            <h3 class="info" id="productsSold">1212</h3>
                            <h6>المنتجات المباعة</h6>
                        </div>
                        <div>
                            <i class="icon-basket-loaded info font-large-2 float-left"></i>
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
                            <h3 class="warning" id="totalRevenue">1212 ج.م</h3>
                            <h6>إجمالي الإيرادات</h6>
                        </div>
                        <div>
                            <i class="icon-pie-chart warning font-large-2 float-left"></i>
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
                            <h3 class="success" id="totalUnsoldProducts">2323</h6>
                            <h6>المنتجات غير المباعة</h6>
                        </div>
                        <div>
                            <i class="icon-handbag success font-large-2 float-left"></i>
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
                            <h3 class="danger" id="totalPurchases"> 2323 ج.م </h3>
                            <h6>إجمالي المشتريات</h6>
                        </div>
                        <div>
                            <i class="icon-wallet danger font-large-2 float-left"></i>
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
                            <h3 class="primary" id="totalProfit"> 2323 ج.م</h3>
                            <h6>إجمالي الأرباح</h6>
                        </div>
                        <div>
                            <i class="icon-graph primary font-large-2 float-left"></i>
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
                            <h3 class="primary" id="availableMoney">2323 ج.م</h3>
                            <h6>المبلغ المتوفر</h6>
                        </div>
                        <div>
                            <i class="icon-wallet primary font-large-2 float-left"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    </div>
    <!-- Date Range Picker -->
    <div class="row">
        <div class="col-12">
        <div class="row">
            <div class="col-md-6">
            <div class="form-group">
                <label for="start_date">Start Date</label>
                <input type="text" id="start_date" name="start_date" class="form-control datepicker">
            </div>
            </div>
            <div class="col-md-6">
            <div class="form-group">
                <label for="end_date">End Date</label>
                <input type="text" id="end_date" name="end_date" class="form-control datepicker">
            </div>
            </div>
        </div>
        </div>
    </div>

@endsection
