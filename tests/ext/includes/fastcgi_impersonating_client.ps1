# Minimal FastCGI client over a Windows named pipe that connects while impersonating
# another local user, like IIS does for the request user (e.g. IUSR) when the
# php-cgi side runs with fastcgi.impersonate=1.
param(
    [Parameter(Mandatory=$true)][string]$User,
    [Parameter(Mandatory=$true)][string]$Password,
    [Parameter(Mandatory=$true)][string]$Pipe,
    [Parameter(Mandatory=$true)][string]$Script,
    [string]$Uri = "/"
)
$ErrorActionPreference = 'Stop'

Add-Type -TypeDefinition @'
using System;
using System.Collections.Generic;
using System.IO;
using System.Runtime.InteropServices;
using System.Security.Principal;
using System.Text;
using Microsoft.Win32.SafeHandles;

public static class DdFcgiClient {
    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool LogonUser(string user, string domain, string password, int logonType, int provider, out IntPtr token);
    [DllImport("advapi32.dll", SetLastError = true)]
    static extern bool ImpersonateLoggedOnUser(IntPtr token);
    [DllImport("advapi32.dll", SetLastError = true)]
    static extern bool RevertToSelf();
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern SafeFileHandle CreateFile(string name, uint access, uint share, IntPtr sa, uint disposition, uint flags, IntPtr template);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool WaitNamedPipe(string name, uint timeout);

    static void Record(Stream s, byte type, byte[] content) {
        int len = content == null ? 0 : content.Length;
        byte[] h = { 1, type, 0, 1, (byte)(len >> 8), (byte)len, 0, 0 };
        s.Write(h, 0, 8);
        if (len > 0) s.Write(content, 0, len);
    }

    static void Length(MemoryStream m, int len) {
        if (len < 128) { m.WriteByte((byte)len); }
        else { m.WriteByte((byte)((len >> 24) | 0x80)); m.WriteByte((byte)(len >> 16)); m.WriteByte((byte)(len >> 8)); m.WriteByte((byte)len); }
    }

    static byte[] Params(Dictionary<string, string> p) {
        MemoryStream m = new MemoryStream();
        foreach (KeyValuePair<string, string> kv in p) {
            byte[] k = Encoding.UTF8.GetBytes(kv.Key), v = Encoding.UTF8.GetBytes(kv.Value);
            Length(m, k.Length); Length(m, v.Length);
            m.Write(k, 0, k.Length); m.Write(v, 0, v.Length);
        }
        return m.ToArray();
    }

    static void ReadExact(Stream s, byte[] buf, int len) {
        int off = 0;
        while (off < len) {
            int n = s.Read(buf, off, len - off);
            if (n <= 0) throw new EndOfStreamException("pipe closed after " + off + "/" + len + " bytes");
            off += n;
        }
    }

    public static string Exchange(Stream s, string script, string uri) {
        Record(s, 1 /* BEGIN_REQUEST */, new byte[] { 0, 1 /* RESPONDER */, 0, 0, 0, 0, 0, 0 });
        Dictionary<string, string> p = new Dictionary<string, string>();
        p["GATEWAY_INTERFACE"] = "CGI/1.1";
        p["SERVER_SOFTWARE"] = "dd-fcgi-impersonation-test";
        p["SERVER_PROTOCOL"] = "HTTP/1.1";
        p["SERVER_NAME"] = "localhost";
        p["SERVER_PORT"] = "80";
        p["REMOTE_ADDR"] = "127.0.0.1";
        p["REQUEST_METHOD"] = "GET";
        p["REQUEST_URI"] = uri;
        p["QUERY_STRING"] = "";
        p["SCRIPT_NAME"] = "/" + Path.GetFileName(script);
        p["SCRIPT_FILENAME"] = script;
        p["DOCUMENT_ROOT"] = Path.GetDirectoryName(script);
        p["HTTP_HOST"] = "localhost";
        Record(s, 4 /* PARAMS */, Params(p));
        Record(s, 4, null);
        Record(s, 5 /* STDIN */, null);
        s.Flush();

        StringBuilder stdout = new StringBuilder(), stderr = new StringBuilder();
        byte[] hdr = new byte[8];
        while (true) {
            ReadExact(s, hdr, 8);
            int len = (hdr[4] << 8) | hdr[5], pad = hdr[6];
            byte[] body = new byte[len + pad];
            ReadExact(s, body, len + pad);
            if (hdr[1] == 6) stdout.Append(Encoding.UTF8.GetString(body, 0, len));
            else if (hdr[1] == 7) stderr.Append(Encoding.UTF8.GetString(body, 0, len));
            else if (hdr[1] == 3) break; // END_REQUEST
        }
        StringBuilder log = new StringBuilder();
        log.AppendLine("client: stderr <<" + stderr.ToString() + ">>");
        log.AppendLine("client: stdout <<" + stdout.ToString() + ">>");
        return log.ToString();
    }

    // Bounded, so that a hung php-cgi still leaves time for the test to dump diagnostics.
    public static string RequestWithTimeout(string user, string password, string pipe, string script, string uri, int timeoutMs) {
        StringBuilder log = new StringBuilder();
        Exception error = null;
        System.Threading.Thread t = new System.Threading.Thread(delegate() {
            try { Request(user, password, pipe, script, uri, log); } catch (Exception e) { error = e; }
        });
        t.IsBackground = true;
        t.Start();
        bool done = t.Join(timeoutMs);
        lock (log) {
            if (!done) log.AppendLine("client: TIMEOUT after " + timeoutMs + "ms");
            if (error != null) log.AppendLine("client: EXCEPTION " + error.ToString());
            return log.ToString();
        }
    }

    static void Request(string user, string password, string pipe, string script, string uri, StringBuilder log) {
        IntPtr token = IntPtr.Zero;
        int[] logonTypes = { 2 /* INTERACTIVE */, 3 /* NETWORK */, 4 /* BATCH */ };
        foreach (int t in logonTypes) {
            if (LogonUser(user, ".", password, t, 0, out token)) { Log(log, "client: LogonUser type " + t + " ok"); break; }
            Log(log, "client: LogonUser type " + t + " failed: " + Marshal.GetLastWin32Error());
            token = IntPtr.Zero;
        }
        if (token == IntPtr.Zero) throw new Exception("client: could not log on " + user);

        Log(log, "client: process identity " + WindowsIdentity.GetCurrent().Name);
        if (!ImpersonateLoggedOnUser(token)) throw new Exception("client: ImpersonateLoggedOnUser failed: " + Marshal.GetLastWin32Error());
        try {
            Log(log, "client: impersonated identity " + WindowsIdentity.GetCurrent(true).Name);
            SafeFileHandle h = null;
            for (int i = 0; i < 200; i++) {
                // No SECURITY_SQOS_PRESENT: the server gets SecurityImpersonation, as with IIS.
                h = CreateFile(pipe, 0xC0000000 /* GENERIC_READ|GENERIC_WRITE */, 0, IntPtr.Zero, 3 /* OPEN_EXISTING */, 0, IntPtr.Zero);
                if (!h.IsInvalid) break;
                int err = Marshal.GetLastWin32Error();
                if (err != 2 /* FILE_NOT_FOUND */ && err != 231 /* PIPE_BUSY */) throw new Exception("client: CreateFile(" + pipe + ") failed: " + err);
                if (err == 231) WaitNamedPipe(pipe, 100); else System.Threading.Thread.Sleep(100);
            }
            if (h == null || h.IsInvalid) throw new Exception("client: pipe " + pipe + " never became available");
            Log(log, "client: connected to " + pipe);

            using (FileStream s = new FileStream(h, FileAccess.ReadWrite, 1, false)) {
                string response = Exchange(s, script, uri);
                lock (log) log.Append(response);
            }
        } finally {
            RevertToSelf();
            CloseHandle(token);
        }
    }

    static void Log(StringBuilder log, string line) {
        lock (log) log.AppendLine(line);
    }
}
'@

try {
    [DdFcgiClient]::RequestWithTimeout($User, $Password, $Pipe, $Script, $Uri, 15000)
} catch {
    Write-Output "client: EXCEPTION $($_.Exception.ToString())"
    exit 1
}
