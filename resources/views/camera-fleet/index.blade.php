@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Camera Fleet')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Fleet')

@section('page_title', 'Camera fleet')

@section('page_lead', 'Add cameras, test their streams, and manage recording settings. Saved stream profiles do not guarantee a camera is online.')

@section('content')
    <livewire:camera-fleet.manager />
@endsection

@push('scripts')
    <script src="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}" defer data-navigate-once></script>
    <script src="{{ asset('js/camera-motion-editor.js').'?v='.filemtime(public_path('js/camera-motion-editor.js')) }}" defer data-navigate-once></script>
    <script src="{{ asset('js/camera-editor-modal.js').'?v='.filemtime(public_path('js/camera-editor-modal.js')) }}" defer data-navigate-once></script>
@endpush
