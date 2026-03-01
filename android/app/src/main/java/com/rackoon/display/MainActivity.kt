package com.rackoon.display

import android.content.Context
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.clickable
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import okhttp3.Request
import okhttp3.Response
import okhttp3.sse.EventSource
import okhttp3.sse.EventSourceListener
import okhttp3.sse.EventSources
import org.json.JSONObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private data class DisplayState(
    val message: String,
    val hint: String,
    val color: Color,
    val connection: String = "",
)

private data class MonitorTexts(
    val standbyText: String,
    val detectingText: String,
    val successParkingText: String,
    val successServiceText: String,
    val failedText: String,
)

private data class UiTexts(
    val hintServiceLobby: String,
    val hintParking: String,
    val hintContactAdmin: String,
    val hintDetecting: String,
    val hintConnected: String,
    val hintRefreshing: String,
    val connEstablishing: String,
    val connConnecting: String,
    val connConnected: String,
    val connSseFallback: String,
    val connFeedConnected: String,
    val connFeedError: String,
    val connRestoring: String,
    val connCheckUrl: String,
    val cfgSave: String,
    val cfgCancel: String,
    val cfgTitle: String,
    val cfgLabel: String,
)

class MainActivity : ComponentActivity() {
    private val httpClient: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .addInterceptor(HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BASIC })
            .build()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        setContent {
            KioskScreen(httpClient)
        }
    }

    override fun onResume() {
        super.onResume()
        // Attempts lock-task mode for managed/kiosk devices.
        runCatching { startLockTask() }
    }
}

@Composable
private fun KioskScreen(client: OkHttpClient) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var showConfig by remember { mutableStateOf(false) }
    var baseUrl by remember { mutableStateOf(loadBaseUrl(context)) }
    var editUrl by remember { mutableStateOf(baseUrl) }
    var monitorTexts by remember { mutableStateOf(defaultMonitorTexts(context)) }
    val uiTexts = remember { defaultUiTexts(context) }
    var state by remember {
        mutableStateOf(
            DisplayState(
                message = monitorTexts.standbyText,
                hint = uiTexts.hintConnected,
                color = Color(0xFF1F2937),
                connection = uiTexts.connEstablishing,
            )
        )
    }

    DisposableEffect(baseUrl) {
        val streamUrl = buildStreamUrl(baseUrl)
        val feedUrl = buildFeedUrl(baseUrl)
        val configUrl = buildConfigUrl(baseUrl)
        val factory = EventSources.createFactory(client)
        var closedByUs = false
        var source: EventSource? = null
        var pollJob: Job? = null
        var reconnectJob: Job? = null
        var configJob: Job? = null
        var lastDisplayAtMs = 0L

        fun applyMessage(status: String?, message: String) {
            val mapped = mapMessage(status, message, monitorTexts, uiTexts)
            state = mapped.copy(connection = state.connection)
        }

        fun loadMonitorConfig() {
            configJob?.cancel()
            configJob = scope.launch(Dispatchers.IO) {
                runCatching {
                    val req = Request.Builder().url(configUrl).build()
                    client.newCall(req).execute().use { resp ->
                        if (!resp.isSuccessful) return@use
                        val body = resp.body?.string().orEmpty()
                        val root = JSONObject(body)
                        val cfg = root.optJSONObject("config") ?: return@use
                        val loaded = MonitorTexts(
                            standbyText = cfg.optString("standby_text", monitorTexts.standbyText),
                            detectingText = cfg.optString("detecting_text", monitorTexts.detectingText),
                            successParkingText = cfg.optString("success_parking_text", monitorTexts.successParkingText),
                            successServiceText = cfg.optString("success_service_text", monitorTexts.successServiceText),
                            failedText = cfg.optString("failed_text", monitorTexts.failedText),
                        )
                        withContext(Dispatchers.Main) {
                            monitorTexts = loaded
                            if (state.message.isBlank() || state.message == monitorTexts.standbyText) {
                                applyMessage("standby", loaded.standbyText)
                            }
                        }
                    }
                }
            }
        }

        fun startPolling() {
            if (pollJob != null) return
            state = state.copy(connection = uiTexts.connSseFallback)
            pollJob = scope.launch(Dispatchers.IO) {
                while (isActive && !closedByUs) {
                    runCatching {
                        val req = Request.Builder().url(feedUrl).build()
                        client.newCall(req).execute().use { resp ->
                            if (!resp.isSuccessful) return@use
                            val body = resp.body?.string().orEmpty()
                            val root = JSONObject(body)
                            val items = root.optJSONArray("items") ?: return@use
                            if (items.length() == 0) return@use
                            val first = items.optJSONObject(0) ?: return@use
                            val status = first.optString("display_status", "")
                            val direct = first.optString("display_message", "")
                            val msg = if (direct.isNotBlank()) direct else deriveDisplayMessage(first, monitorTexts)
                            withContext(Dispatchers.Main) {
                                if (status.isNotBlank()) {
                                    applyMessage(status, msg)
                                } else {
                                    applyMessage(null, msg)
                                }
                                state = state.copy(connection = uiTexts.connFeedConnected)
                            }
                        }
                    }.onFailure {
                        withContext(Dispatchers.Main) {
                            state = state.copy(connection = uiTexts.connFeedError)
                        }
                    }
                    delay(1000)
                }
            }
        }

        var connect: () -> Unit = {}
        val scheduleReconnect: () -> Unit = {
            reconnectJob?.cancel()
            reconnectJob = scope.launch {
                delay(1500)
                if (!closedByUs && pollJob == null) {
                    connect()
                }
            }
        }

        connect = {
            state = state.copy(connection = "${uiTexts.connConnecting}: $streamUrl")
            applyMessage("detecting", monitorTexts.detectingText)
            source = factory.newEventSource(
                Request.Builder().url(streamUrl).build(),
                object : EventSourceListener() {
                    override fun onOpen(eventSource: EventSource, response: Response) {
                        loadMonitorConfig()
                        applyMessage("standby", monitorTexts.standbyText)
                        state = state.copy(connection = uiTexts.connConnected)
                    }

                    override fun onEvent(
                        eventSource: EventSource,
                        id: String?,
                        type: String?,
                        data: String,
                    ) {
                        val json = JSONObject(data)
                        if (type == "display") {
                            val status = json.optString("display_status", "")
                            val message = json.optString("display_message", monitorTexts.standbyText)
                            lastDisplayAtMs = System.currentTimeMillis()
                            if (status.isNotBlank()) {
                                applyMessage(status, message)
                            } else {
                                applyMessage(null, message)
                            }
                            state = state.copy(connection = uiTexts.connConnected)
                        } else if (type == "ping") {
                            val now = System.currentTimeMillis()
                            if (now - lastDisplayAtMs > 6000) {
                                val message = json.optString("display_message", monitorTexts.standbyText)
                                applyMessage("standby", message)
                            }
                        }
                    }

                    override fun onFailure(
                        eventSource: EventSource,
                        t: Throwable?,
                        response: Response?,
                    ) {
                        if (closedByUs) {
                            return
                        }
                        val code = response?.code ?: -1
                        if (code == 404 || code == 400 || code == 405) {
                            startPolling()
                            return
                        }
                        applyMessage("detecting", monitorTexts.detectingText)
                        state = state.copy(
                            hint = uiTexts.connCheckUrl,
                            connection = uiTexts.connRestoring,
                        )
                        scheduleReconnect()
                    }
                }
            )
        }

        loadMonitorConfig()
        connect()
        onDispose {
            closedByUs = true
            configJob?.cancel()
            reconnectJob?.cancel()
            pollJob?.cancel()
            source?.cancel()
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(state.color)
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Row(modifier = Modifier.padding(bottom = 12.dp)) {
            Text(
                text = state.connection,
                color = Color(0xFFE5E7EB),
                fontSize = 16.sp,
                modifier = Modifier.clickable {
                            editUrl = baseUrl
                            showConfig = true
                },
            )
        }
        Text(
            text = state.message,
            color = Color.White,
            fontSize = 44.sp,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
            lineHeight = 52.sp,
        )
        Text(
            text = state.hint,
            color = Color(0xFFE5E7EB),
            fontSize = 20.sp,
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(top = 20.dp),
        )
    }

    if (showConfig) {
        AlertDialog(
            onDismissRequest = { showConfig = false },
            confirmButton = {
                Button(onClick = {
                    val normalized = normalizeApiUrl(editUrl)
                    baseUrl = normalized
                    saveBaseUrl(context, normalized)
                    showConfig = false
                }) { Text(uiTexts.cfgSave) }
            },
            dismissButton = { Button(onClick = { showConfig = false }) { Text(uiTexts.cfgCancel) } },
            title = { Text(uiTexts.cfgTitle) },
            text = {
                OutlinedTextField(
                    value = editUrl,
                    onValueChange = { editUrl = it },
                    label = { Text(uiTexts.cfgLabel) },
                )
            },
        )
    }
}

private fun mapMessage(status: String?, message: String, texts: MonitorTexts, ui: UiTexts): DisplayState {
    val normalized = status?.trim().orEmpty()
    return when {
        normalized == "success_service" || message.trim() == texts.successServiceText -> DisplayState(
            message = if (message.isBlank()) texts.successServiceText else message,
            hint = ui.hintServiceLobby,
            color = Color(0xFFB91C1C),
        )

        normalized == "success_parking" || message.trim() == texts.successParkingText -> DisplayState(
            message = if (message.isBlank()) texts.successParkingText else message,
            hint = ui.hintParking,
            color = Color(0xFF166534),
        )

        normalized == "failed" || message.trim() == texts.failedText -> DisplayState(
            message = if (message.isBlank()) texts.failedText else message,
            hint = ui.hintContactAdmin,
            color = Color(0xFF374151),
        )

        normalized == "detecting" || message.trim() == texts.detectingText -> DisplayState(
            message = if (message.isBlank()) texts.detectingText else message,
            hint = ui.hintDetecting,
            color = Color(0xFF1D4ED8),
        )

        normalized == "standby" || message.trim() == texts.standbyText -> DisplayState(
            message = if (message.isBlank()) texts.standbyText else message,
            hint = ui.hintConnected,
            color = Color(0xFF1F2937),
        )

        else -> DisplayState(
            message = message,
            hint = ui.hintRefreshing,
            color = Color(0xFF1F2937),
        )
    }
}

private fun buildStreamUrl(baseUrl: String): String {
    val normalized = normalizeApiUrl(baseUrl)
    return "$normalized?action=display-stream"
}

private fun buildFeedUrl(baseUrl: String): String {
    val normalized = normalizeApiUrl(baseUrl)
    return "$normalized?action=display-feed"
}

private fun buildConfigUrl(baseUrl: String): String {
    val normalized = normalizeApiUrl(baseUrl)
    return "$normalized?action=display-config"
}

private fun normalizeBaseUrl(url: String): String {
    val trimmed = url.trim()
    if (trimmed.startsWith("http://") || trimmed.startsWith("https://")) {
        return trimmed.trimEnd('/')
    }
    return "https://${trimmed.trimEnd('/')}"
}

private fun normalizeApiUrl(url: String): String {
    val baseNoQuery = normalizeBaseUrl(url).substringBefore('?').trimEnd('/')
    return if (baseNoQuery.endsWith("/api.php")) {
        baseNoQuery
    } else {
        "$baseNoQuery/api.php"
    }
}

private fun loadBaseUrl(context: Context): String {
    val prefs = context.getSharedPreferences("planner_display", Context.MODE_PRIVATE)
    val saved = prefs.getString("base_url", null)?.trim().orEmpty()
    if (saved.isBlank()) return BuildConfig.API_BASE_URL
    if (saved.contains("10.0.2.2") || saved.contains("127.0.0.1") || saved.contains(":8080")) {
        return BuildConfig.API_BASE_URL
    }
    if (saved.startsWith("http://one.crebit.eu")) {
        return normalizeApiUrl(saved.replaceFirst("http://", "https://"))
    }
    return normalizeApiUrl(saved)
}

private fun saveBaseUrl(context: Context, url: String) {
    val prefs = context.getSharedPreferences("planner_display", Context.MODE_PRIVATE)
    prefs.edit().putString("base_url", normalizeApiUrl(url)).apply()
}

private fun deriveDisplayMessage(item: JSONObject, texts: MonitorTexts): String {
    val allowed = item.optInt("allowed", 0) == 1
    if (!allowed) return texts.failedText
    return if (item.optString("zone", "") == "service_lobby") {
        texts.successServiceText
    } else {
        texts.successParkingText
    }
}

private fun defaultMonitorTexts(context: Context): MonitorTexts = MonitorTexts(
    standbyText = context.getString(R.string.monitor_standby),
    detectingText = context.getString(R.string.monitor_detecting),
    successParkingText = context.getString(R.string.monitor_success_parking),
    successServiceText = context.getString(R.string.monitor_success_service),
    failedText = context.getString(R.string.monitor_failed),
)

private fun defaultUiTexts(context: Context): UiTexts = UiTexts(
    hintServiceLobby = context.getString(R.string.hint_service_lobby),
    hintParking = context.getString(R.string.hint_parking),
    hintContactAdmin = context.getString(R.string.hint_contact_admin),
    hintDetecting = context.getString(R.string.hint_detecting),
    hintConnected = context.getString(R.string.hint_connected),
    hintRefreshing = context.getString(R.string.hint_refreshing),
    connEstablishing = context.getString(R.string.conn_establishing),
    connConnecting = context.getString(R.string.conn_connecting),
    connConnected = context.getString(R.string.conn_connected),
    connSseFallback = context.getString(R.string.conn_sse_fallback),
    connFeedConnected = context.getString(R.string.conn_feed_connected),
    connFeedError = context.getString(R.string.conn_feed_error),
    connRestoring = context.getString(R.string.conn_restoring),
    connCheckUrl = context.getString(R.string.conn_check_url),
    cfgSave = context.getString(R.string.cfg_save),
    cfgCancel = context.getString(R.string.cfg_cancel),
    cfgTitle = context.getString(R.string.cfg_title),
    cfgLabel = context.getString(R.string.cfg_label),
)
