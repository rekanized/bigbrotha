@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | ONVIF Sweep')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Discovery')

@section('page_title', 'ONVIF sweep')

@section('page_lead', 'Start with multicast discovery, then fall back to a direct ONVIF probe when you already know the endpoint.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
    <a class="button button--primary" href="{{ route('live-wall.index') }}" wire:navigate>Live wall</a>
@endsection

@section('content')
    <livewire:discovery.onvif-sweep />
@endsection