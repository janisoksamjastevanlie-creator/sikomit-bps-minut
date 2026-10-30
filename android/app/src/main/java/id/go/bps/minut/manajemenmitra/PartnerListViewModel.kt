package id.go.bps.minut.manajemenmitra

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import id.go.bps.minut.manajemenmitra.data.PartnerApiException
import id.go.bps.minut.manajemenmitra.data.PartnerDetail
import id.go.bps.minut.manajemenmitra.data.PartnerRepository
import id.go.bps.minut.manajemenmitra.data.PartnerVerificationRequest
import id.go.bps.minut.manajemenmitra.data.PartnerWriteRequest
import id.go.bps.minut.manajemenmitra.data.PartnerSummary
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class PartnerListUiState(
    val items: List<PartnerSummary> = emptyList(),
    val total: Int = 0,
    val limit: Int = 50,
    val query: String = "",
    val status: String = "",
    val loading: Boolean = false,
    val loaded: Boolean = false,
    val forbidden: Boolean = false,
    val error: String? = null
)

class PartnerListViewModel(private val repository: PartnerRepository = PartnerRepository()) : ViewModel() {
    private val _state = MutableStateFlow(PartnerListUiState())
    val state: StateFlow<PartnerListUiState> = _state.asStateFlow()

    fun load(query: String = _state.value.query, status: String = _state.value.status, reset: Boolean = true) {
        if (_state.value.loading) return
        val current = _state.value
        val offset = if (reset) 0 else current.items.size
        _state.value = current.copy(
            query = query,
            status = status,
            items = if (reset) emptyList() else current.items,
            loading = true,
            loaded = false,
            forbidden = false,
            error = null
        )
        viewModelScope.launch {
            runCatching { repository.list(query, status, current.limit, offset) }
                .onSuccess { response ->
                    val latest = _state.value
                    _state.value = latest.copy(
                        items = if (reset) response.items else latest.items + response.items,
                        total = response.total,
                        limit = response.limit,
                        loading = false,
                        loaded = true
                    )
                }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        loading = false,
                        loaded = true,
                        forbidden = failure is PartnerApiException && failure.statusCode == 403,
                        error = failure.message ?: "Daftar mitra gagal dimuat."
                    )
                }
        }
    }

    fun loadMore() {
        val current = _state.value
        if (!current.loading && current.items.size < current.total) {
            load(current.query, current.status, reset = false)
        }
    }
}

data class PartnerProfileUiState(
    val profile: PartnerDetail? = null,
    val loading: Boolean = false,
    val saving: Boolean = false,
    val saved: Boolean = false,
    val forbidden: Boolean = false,
    val error: String? = null
)

class PartnerProfileViewModel(private val repository: PartnerRepository = PartnerRepository()) : ViewModel() {
    private val _state = MutableStateFlow(PartnerProfileUiState())
    val state: StateFlow<PartnerProfileUiState> = _state.asStateFlow()

    fun load(id: String) {
        if (_state.value.loading) return
        _state.value = _state.value.copy(loading = true, error = null, forbidden = false)
        viewModelScope.launch {
            runCatching { repository.detail(id) }
                .onSuccess { _state.value = PartnerProfileUiState(profile = it) }
                .onFailure { failure ->
                    _state.value = PartnerProfileUiState(
                        loading = false,
                        forbidden = failure is PartnerApiException && failure.statusCode == 403,
                        error = failure.message ?: "Profil mitra gagal dimuat."
                    )
                }
        }
    }

    fun save(id: String?, request: PartnerWriteRequest) {
        if (_state.value.saving) return
        _state.value = _state.value.copy(saving = true, saved = false, error = null)
        viewModelScope.launch {
            runCatching {
                if (id.isNullOrBlank()) repository.create(request) else repository.update(id, request)
            }.onSuccess { profile ->
                _state.value = PartnerProfileUiState(profile = profile, saved = true)
            }.onFailure { failure ->
                _state.value = _state.value.copy(
                    saving = false,
                    forbidden = failure is PartnerApiException && failure.statusCode == 403,
                    error = failure.message ?: "Data mitra gagal disimpan."
                )
            }
        }
    }

    fun verify(id: String, status: String, catatan: String) {
        if (_state.value.saving) return
        _state.value = _state.value.copy(saving = true, saved = false, error = null)
        viewModelScope.launch {
            runCatching { repository.verify(id, PartnerVerificationRequest(status, catatan.trim())) }
                .onSuccess { profile -> _state.value = PartnerProfileUiState(profile = profile, saved = true) }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        saving = false,
                        forbidden = failure is PartnerApiException && failure.statusCode == 403,
                        error = failure.message ?: "Verifikasi mitra gagal disimpan."
                    )
                }
        }
    }

    fun clearSavedFlag() {
        _state.value = _state.value.copy(saved = false)
    }
}
