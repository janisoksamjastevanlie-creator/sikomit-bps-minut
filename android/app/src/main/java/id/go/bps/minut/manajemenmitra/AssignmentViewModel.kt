package id.go.bps.minut.manajemenmitra

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import id.go.bps.minut.manajemenmitra.data.AssignmentApiException
import id.go.bps.minut.manajemenmitra.data.AssignmentOptionsResponse
import id.go.bps.minut.manajemenmitra.data.AssignmentRepository
import id.go.bps.minut.manajemenmitra.data.AssignmentSummary
import id.go.bps.minut.manajemenmitra.data.AssignmentWriteRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class AssignmentListUiState(
    val items: List<AssignmentSummary> = emptyList(),
    val total: Int = 0,
    val query: String = "",
    val loading: Boolean = false,
    val loaded: Boolean = false,
    val forbidden: Boolean = false,
    val error: String? = null
)

class AssignmentListViewModel(private val repository: AssignmentRepository = AssignmentRepository()) : ViewModel() {
    private val _state = MutableStateFlow(AssignmentListUiState())
    val state: StateFlow<AssignmentListUiState> = _state.asStateFlow()

    fun load(query: String = _state.value.query) {
        if (_state.value.loading) return
        _state.value = _state.value.copy(query = query, loading = true, error = null, forbidden = false)
        viewModelScope.launch {
            runCatching { repository.list(query, "", 100, 0) }
                .onSuccess { response -> _state.value = _state.value.copy(items = response.items, total = response.total, loading = false, loaded = true) }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        loading = false,
                        loaded = true,
                        forbidden = failure is AssignmentApiException && failure.statusCode == 403,
                        error = failure.message ?: "Daftar penugasan gagal dimuat."
                    )
                }
        }
    }
}

data class AssignmentOptionsUiState(
    val options: AssignmentOptionsResponse = AssignmentOptionsResponse(),
    val loading: Boolean = false,
    val saving: Boolean = false,
    val saved: AssignmentSummary? = null,
    val forbidden: Boolean = false,
    val error: String? = null
)

class AssignmentOptionsViewModel(private val repository: AssignmentRepository = AssignmentRepository()) : ViewModel() {
    private val _state = MutableStateFlow(AssignmentOptionsUiState())
    val state: StateFlow<AssignmentOptionsUiState> = _state.asStateFlow()

    fun loadOptions() {
        if (_state.value.loading || _state.value.options.kegiatan.isNotEmpty()) return
        _state.value = _state.value.copy(loading = true, error = null, forbidden = false)
        viewModelScope.launch {
            runCatching { repository.options() }
                .onSuccess { _state.value = _state.value.copy(options = it, loading = false) }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        loading = false,
                        forbidden = failure is AssignmentApiException && failure.statusCode == 403,
                        error = failure.message ?: "Pilihan penugasan gagal dimuat."
                    )
                }
        }
    }

    fun create(request: AssignmentWriteRequest) {
        if (_state.value.saving) return
        _state.value = _state.value.copy(saving = true, saved = null, error = null)
        viewModelScope.launch {
            runCatching { repository.create(request) }
                .onSuccess { _state.value = _state.value.copy(saving = false, saved = it) }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        saving = false,
                        forbidden = failure is AssignmentApiException && failure.statusCode == 403,
                        error = failure.message ?: "Penugasan gagal disimpan."
                    )
                }
        }
    }
}
