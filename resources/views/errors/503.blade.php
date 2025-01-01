@extends('errors::layout')

@section('title', 'Error')

@section('message', 'System updating...')

{{ $exception->getMessage() }}
