@extends('admin.layouts.app')

@section('page.title', 'المتواجدين الان')
@section('breadcrumbs')
    <div class="breadcrumbs">
        <div class="col-sm-12">
            <div class="page-header float-right">
                <div class="page-title">
                    <h1>المتواجدين الان</h1>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('content')
    <div class="content mt-3 admin-learning admin-learning--online">
        <div class="row">
            <div class="col-md-12">
                <div class="card admin-card">
                    <div class="card-header"><i class="fa fa-map"></i><strong class="card-title pr-2">المتواجدين الان</strong></div>
                    <div id="map" class="online-map-canvas"></div>
                </div>
            </div>
        </div>
    </div> <!-- .content -->
@endsection
@section('scripts')
    <script>
        let providers = {!! json_encode($providers) !!};
        function initMap() {
            navigator.geolocation.getCurrentPosition(function(position) {
                var pos = {
                    lat: 24.774265,
                    lng: 46.738586
                };
                map = new google.maps.Map(document.getElementById('map'), {
                    center: {lat: pos.lat, lng: pos.lng},
                    zoom: 5,
                    mapTypeControlOptions: {
                        style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
                        position: google.maps.ControlPosition.BOTTOM_LEFT
                    },
                });
                var icon = {
                    url: "/images/markerImg.png", // url
                    scaledSize: new google.maps.Size(40, 40), // scaled size
                    origin: new google.maps.Point(0,0), // origin
                    anchor: new google.maps.Point(0, 0) // anchor
                };
                for (var i in providers) {
                    if (providers.hasOwnProperty(i)) {
                        if (providers[i] != null) {
                            let marker = new google.maps.Marker({
                                position: {lat: providers[i]['lat'], lng: providers[i]['lng']},
                                animation: google.maps.Animation.DROP,
                                map: map,
                                icon : icon,
                                title: providers[i]['name']
                            });
                            google.maps.event.addListener(marker, 'click', function() {
                                infowindow = new google.maps.InfoWindow();
                                infowindow.setContent('<span class="online-map__marker-label">'+marker.title+'</span>'); // contentString can be HTML.
                                infowindow.setPosition(marker.position);

                                infowindow.open(map);
                            });
                        }
                    }
                }
            })
        }
    </script>
    @if (filled(config('services.google.maps_browser_key')))
        <script async defer
                src="https://maps.googleapis.com/maps/api/js?key={{ rawurlencode((string) config('services.google.maps_browser_key')) }}&callback=initMap">
        </script>
    @endif
@endsection
@section('styles')
    <link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/admin-learning-views.css') }}">
@endsection
