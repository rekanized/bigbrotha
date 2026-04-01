@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | Camera Fleet')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Fleet')

@section('page_title', 'Camera fleet')

@section('page_lead', 'Create, edit, enable, disable, and inspect saved cameras from the operator GUI, then retrieve RTSP stream URLs directly from ONVIF media profiles.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('live-wall.index') }}" wire:navigate>Live wall</a>
    <a class="button button--primary" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>Discover devices</a>
@endsection

@section('content')
    <livewire:camera-fleet.manager />
@endsection