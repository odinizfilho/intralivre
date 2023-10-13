<x-app-layout>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Visualizar Documento') }}
            </h2>
        </x-slot>
    
        <div>
            <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h1 class="text-2xl font-semibold mb-4">{{ $documento->nome }}</h1>
    
                        <div class="mb-4">
                            <a href="{{ route('documentos.export', ['id' => $documento->id, 'format' => 'pdf']) }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover-bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 ml-4">{{ __('Exportar como PDF') }}</a>
                        </div>
    
                        <!-- Adicione a div para visualização do PDF aqui -->
                        <iframe class="w-full h-screen" src="{{ route('documentos.iframe', ['pdf' => $documento->id]) }}"  frameborder="0"
                            allowfullscreen>
                    </div>
                </div>
            </div>
        </div>

    
    
</x-app-layout>
