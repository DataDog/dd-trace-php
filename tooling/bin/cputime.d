#!/usr/sbin/dtrace -b 16m -Zs

/*
Launch with something like:
    sudo ./cputime.d -p $pid
Where $pid is the pid you want to trace.
You can add `-o logfile` if you want to save the output to a file
*/

#pragma D option quiet

dtrace:::BEGIN
{
    printf("Tracing... Hit Ctrl-C to end.\n");
}

pid$target:ddtrace:_dd_*:entry,
pid$target:ddtrace:ddtrace_*:entry,
pid$target:ddtrace:zm_activate_ddtrace*:entry,
pid$target:ddtrace:zif_dd_trace*:entry,
pid$target:ddtrace:zif_ddtrace**:entry
{
    self->depth++;
    self->exclude[self->depth] = 0;
    self->function[self->depth] = vtimestamp;
}

pid$target:ddtrace:_dd_*:return,
pid$target:ddtrace:ddtrace_*:return,
pid$target:ddtrace:zm_activate_ddtrace*:return,
pid$target:ddtrace:zif_dd_trace*:return,
pid$target:ddtrace:zif_ddtrace*:return
/self->function[self->depth]/
{
    this->oncpu_incl = vtimestamp - self->function[self->depth];
    this->oncpu_excl = this->oncpu_incl - self->exclude[self->depth];
    self->function[self->depth] = 0;
    self->exclude[self->depth] = 0;
    this->name = probefunc;

    @num[this->name] = count();
    @num["total"] = count();
    @types_incl[this->name] = sum(this->oncpu_incl);
    @types_excl[this->name] = sum(this->oncpu_excl);
    @types_excl["total"] = sum(this->oncpu_excl);

    self->depth--;
    self->exclude[self->depth] += this->oncpu_incl;
}


dtrace:::END
{
    printf("\nCount,\n");
    printf("   %-48s %8s\n", "NAME", "COUNT");
    printa("   %-48s %@8d\n", @num);

    normalize(@types_excl, 1000);
    printf("\nExclusive function on-CPU times (us),\n");
    printf("   %-48s %8s\n", "NAME", "TOTAL");
    printa("   %-48s %@8d\n", @types_excl);

    normalize(@types_incl, 1000);
    printf("\nInclusive function on-CPU times (us),\n");
    printf("   %-48s %8s\n", "NAME", "TOTAL");
    printa("   %-48s %@8d\n", @types_incl);
}
