@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Wall Tiles')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Layouts')

@section('page_title', 'Wall tiles')

@section('page_lead', 'Choose cameras and arrange them into named walls. Save a layout, then open it in Live Wall to watch the feeds.')

@section('content')
    <livewire:live-wall.tiles-manager />
@endsection

@push('scripts')
    <script src="{{ asset('js/vendor/sortable.min.js').'?v='.filemtime(public_path('js/vendor/sortable.min.js')) }}" defer data-navigate-once></script>
    <script src="{{ asset('js/wall-tiles-builder.js').'?v='.filemtime(public_path('js/wall-tiles-builder.js')) }}" defer data-navigate-once></script>
@endpush
