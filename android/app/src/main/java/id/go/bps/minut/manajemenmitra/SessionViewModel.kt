package id.go.bps.minut.manajemenmitra

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import id.go.bps.minut.manajemenmitra.data.AuthRepository
import id.go.bps.minut.manajemenmitra.data.DashboardSummary
import id.go.bps.minut.manajemenmitra.data.SessionUser
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class SessionUiState(
    val user: SessionUser? = null,
    val dashboard: DashboardSummary? = null,
    val loading: Boolean = false,
    val error: String? = null
)

class SessionViewModel(private val repository: AuthRepository = AuthRepository()) : ViewModel() {
    private val _state = MutableStateFlow(SessionUiState())
    val state: StateFlow<SessionUiState> = _state.asStateFlow()

    fun login(username: String, password: String) {
        if (_state.value.loading) return
        _state.value = _state.value.copy(loading = true, error = null)
        viewModelScope.launch {
            runCatching { repository.login(username, password) }
                .onSuccess { (user, dashboard) -> _state.value = SessionUiState(user, dashboard) }
                .onFailure { _state.value = SessionUiState(error = it.message ?: "Tidak dapat terhubung ke server.") }
        }
    }

    fun refresh() {
        if (_state.value.loading || _state.value.user == null) return
        _state.value = _state.value.copy(loading = true, error = null)
        viewModelScope.launch {
            runCatching { repository.refreshDashboard() }
                .onSuccess { _state.value = _state.value.copy(dashboard = it, loading = false) }
                .onFailure { _state.value = _state.value.copy(loading = false, error = it.message ?: "Dashboard gagal dimuat.") }
        }
    }

    fun changePassword(current: String, newPassword: String, confirmation: String) {
        if (_state.value.loading) return
        if (newPassword != confirmation) {
            _state.value = _state.value.copy(error = "Konfirmasi password baru tidak sama.")
            return
        }
        if (newPassword.length < 12 || newPassword.none(Char::isLetter) || newPassword.none(Char::isDigit)) {
            _state.value = _state.value.copy(error = "Password baru minimal 12 karakter dan harus berisi huruf serta angka.")
            return
        }
        _state.value = _state.value.copy(loading = true, error = null)
        viewModelScope.launch {
            runCatching { repository.changePassword(current, newPassword) }
                .onSuccess {
                    runCatching { repository.refreshDashboard() }
                        .onSuccess { dashboard ->
                            _state.value = _state.value.copy(
                                user = _state.value.user?.copy(mustChangePassword = false),
                                dashboard = dashboard,
                                loading = false,
                                error = null
                            )
                        }
                        .onFailure { failure ->
                            _state.value = _state.value.copy(
                                user = _state.value.user?.copy(mustChangePassword = false),
                                loading = false,
                                error = failure.message ?: "Password berubah, tetapi dashboard gagal dimuat."
                            )
                        }
                }
                .onFailure { failure ->
                    _state.value = _state.value.copy(loading = false, error = failure.message ?: "Password gagal diperbarui.")
                }
        }
    }

    fun logout() {
        viewModelScope.launch {
            repository.logout()
            _state.value = SessionUiState()
        }
    }
}
