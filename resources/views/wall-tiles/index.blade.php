@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Wall Tiles')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Layouts')

@section('page_title', 'Wall tiles')

@section('page_lead', 'Build named live walls, choose which cameras are shown, and control tile orientation and span before operators open the wall.')

@section('content')
    <livewire:live-wall.tiles-manager />
@endsection

@push('scripts')
    <script src="{{ asset('js/vendor/sortable.min.js').'?v='.filemtime(public_path('js/vendor/sortable.min.js')) }}" defer data-navigate-once></script>
    <script src="{{ asset('js/wall-tiles-builder.js').'?v='.filemtime(public_path('js/wall-tiles-builder.js')) }}" defer data-navigate-once></script>
@endpush
