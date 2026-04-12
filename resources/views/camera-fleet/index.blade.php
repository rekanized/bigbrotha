@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Camera Fleet')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Fleet')

@section('page_title', 'Camera fleet')

@section('page_lead', 'Maintain the saved camera inventory, probe new ONVIF endpoints directly, refresh RTSP profiles, and capture previews before feeds reach the wall.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('recordings.index') }}" wire:navigate>Recordings</a>
    <a class="button button--soft" href="{{ route('wall-tiles.index') }}" wire:navigate>Wall tiles</a>
    <a class="button button--primary" href="{{ route('live-wall.index') }}" wire:navigate>Live wall</a>
@endsection

@section('content')
    <livewire:camera-fleet.manager />
@endsection