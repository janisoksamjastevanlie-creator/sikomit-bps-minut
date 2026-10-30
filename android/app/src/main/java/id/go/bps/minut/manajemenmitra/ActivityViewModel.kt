package id.go.bps.minut.manajemenmitra

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import id.go.bps.minut.manajemenmitra.data.ActivityApiException
import id.go.bps.minut.manajemenmitra.data.ActivityRepository
import id.go.bps.minut.manajemenmitra.data.ActivitySummary
import id.go.bps.minut.manajemenmitra.data.ActivityWriteRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class ActivityListUiState(
    val items: List<ActivitySummary> = emptyList(),
    val total: Int = 0,
    val query: String = "",
    val status: String = "",
    val year: String = "",
    val loading: Boolean = false,
    val loaded: Boolean = false,
    val forbidden: Boolean = false,
    val error: String? = null
)

class ActivityListViewModel(private val repository: ActivityRepository = ActivityRepository()) : ViewModel() {
    private val _state = MutableStateFlow(ActivityListUiState())
    val state: StateFlow<ActivityListUiState> = _state.asStateFlow()

    fun load(query: String = _state.value.query, status: String = _state.value.status, year: String = _state.value.year) {
        if (_state.value.loading) return
        _state.value = _state.value.copy(query = query, status = status, year = year, loading = true, error = null, forbidden = false)
        viewModelScope.launch {
            runCatching { repository.list(query, status, year, 100, 0) }
                .onSuccess { response ->
                    _state.value = _state.value.copy(items = response.items, total = response.total, loading = false, loaded = true)
                }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        loading = false,
                        loaded = true,
                        forbidden = failure is ActivityApiException && failure.statusCode == 403,
                        error = failure.message ?: "Daftar kegiatan gagal dimuat."
                    )
                }
        }
    }
}

data class ActivityProfileUiState(
    val activity: ActivitySummary? = null,
    val loading: Boolean = false,
    val saving: Boolean = false,
    val saved: Boolean = false,
    val forbidden: Boolean = false,
    val error: String? = null
)

class ActivityProfileViewModel(private val repository: ActivityRepository = ActivityRepository()) : ViewModel() {
    private val _state = MutableStateFlow(ActivityProfileUiState())
    val state: StateFlow<ActivityProfileUiState> = _state.asStateFlow()

    fun load(id: String) {
        if (_state.value.loading) return
        _state.value = _state.value.copy(loading = true, error = null, forbidden = false)
        viewModelScope.launch {
            runCatching { repository.detail(id) }
                .onSuccess { _state.value = ActivityProfileUiState(activity = it) }
                .onFailure { failure ->
                    _state.value = ActivityProfileUiState(
                        loading = false,
                        forbidden = failure is ActivityApiException && failure.statusCode == 403,
                        error = failure.message ?: "Detail kegiatan gagal dimuat."
                    )
                }
        }
    }

    fun save(id: String?, request: ActivityWriteRequest) {
        if (_state.value.saving) return
        _state.value = _state.value.copy(saving = true, saved = false, error = null)
        viewModelScope.launch {
            runCatching { if (id.isNullOrBlank()) repository.create(request) else repository.update(id, request) }
                .onSuccess { _state.value = ActivityProfileUiState(activity = it, saved = true) }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        saving = false,
                        forbidden = failure is ActivityApiException && failure.statusCode == 403,
                        error = failure.message ?: "Kegiatan gagal disimpan."
                    )
                }
        }
    }

    fun activate(id: String) {
        if (_state.value.saving) return
        _state.value = _state.value.copy(saving = true, saved = false, error = null)
        viewModelScope.launch {
            runCatching { repository.activate(id) }
                .onSuccess { _state.value = ActivityProfileUiState(activity = it, saved = true) }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        saving = false,
                        forbidden = failure is ActivityApiException && failure.statusCode == 403,
                        error = failure.message ?: "Kegiatan gagal diaktifkan."
                    )
                }
        }
    }
}
