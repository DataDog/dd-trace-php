FROM mcr.microsoft.com/windows/servercore:ltsc2019

ADD https://aka.ms/vs/16/release/vs_buildtools.exe /tmp/vs_buildtools.exe
RUN /tmp/vs_buildtools.exe --quiet --wait --add Microsoft.VisualStudio.Workload.VCTools --add Microsoft.Net.Component.4.8.SDK --add Microsoft.VisualStudio.Component.VC.Tools.x86.x64 --add Microsoft.VisualStudio.Component.Windows10SDK.19041
