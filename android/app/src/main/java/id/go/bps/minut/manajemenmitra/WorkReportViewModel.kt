package id.go.bps.minut.manajemenmitra

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import id.go.bps.minut.manajemenmitra.data.WorkReportApiException
import id.go.bps.minut.manajemenmitra.data.WorkReportRepository
import id.go.bps.minut.manajemenmitra.data.WorkReportSummary
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class WorkReportUiState(
    val items: List<WorkReportSummary> = emptyList(),
    val loading: Boolean = false,
    val loaded: Boolean = false,
    val saving: Boolean = false,
    val forbidden: Boolean = false,
    val error: String? = null,
    val message: String? = null
)

class WorkReportViewModel(private val repository: WorkReportRepository = WorkReportRepository()) : ViewModel() {
    private val _state = MutableStateFlow(WorkReportUiState())
    val state: StateFlow<WorkReportUiState> = _state.asStateFlow()

    fun load() {
        viewModelScope.launch {
            _state.value = _state.value.copy(loading = true, error = null, forbidden = false, message = null)
            runCatching { repository.list() }
                .onSuccess { _state.value = WorkReportUiState(items = it.items, loaded = true) }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        loading = false,
                        loaded = true,
                        forbidden = failure is WorkReportApiException && failure.statusCode == 403,
                        error = failure.message ?: "Terjadi kesalahan saat memuat laporan."
                    )
                }
        }
    }

    fun review(id: String, decision: String, note: String?) {
        viewModelScope.launch {
            _state.value = _state.value.copy(saving = true, error = null, message = null)
            runCatching { repository.review(id, decision, note) }
                .onSuccess { reviewed ->
                    _state.value = _state.value.copy(
                        items = _state.value.items.map { if (it.id == id) reviewed else it },
                        saving = false,
                        message = "Keputusan ${labelWorkReportStatus(reviewed.status)} tersimpan."
                    )
                }
                .onFailure { failure ->
                    _state.value = _state.value.copy(
                        saving = false,
                        forbidden = failure is WorkReportApiException && failure.statusCode == 403,
                        error = failure.message ?: "Pemeriksaan gagal disimpan."
                    )
                }
        }
    }
}

fun labelWorkReportStatus(status: String): String = when (status.uppercase()) {
    "DRAFT" -> "Draft"
    "DIAJUKAN" -> "Menunggu pemeriksaan"
    "PERLU_PERBAIKAN" -> "Perlu perbaikan"
    "DITERIMA" -> "Diterima"
    "DITOLAK" -> "Ditolak"
    else -> status.replace('_', ' ').lowercase().replaceFirstChar { it.uppercase() }
}
